<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\ClientSettlement;
use App\Models\Invoice;
use App\Models\OrderCollection;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\StorageCharge;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What a client is owed after their products sold. Customer money collected for the client sits in the ledger as "client funds"
 * (a liability, not revenue). A settlement does three things at once and posts one balanced journal for them:
 *   1. nets the commission the client owes (and storage fees, when allowed) against the money held — each fee is recorded as an
 *      ordinary ClientHub payment on its own invoice, so the fee becomes company income exactly once and the invoice shows paid;
 *   2. remits the rest (or the part chosen) to the client;
 *   3. leaves any unpaid remainder as client funds still held.
 * The client's own product cost is never deducted again — they paid it when the lot was billed. Recording a settlement does not move
 * real money; it records that Finance has done so.
 */
class ClientSettlementService
{
    public static function collected(Client $c): int
    {
        return (int) OrderCollection::whereHas('order.project', fn ($q) => $q->where('client_id', $c->id))->sum('amount');
    }

    public static function settledOut(Client $c): int
    {
        return (int) ClientSettlement::where('client_id', $c->id)->selectRaw('COALESCE(SUM(commission_netted + storage_netted + paid),0) s')->value('s');
    }

    /** Customer money held for the client that has not been netted or remitted. */
    public static function fundsHeld(Client $c): int
    {
        return self::collected($c) - self::settledOut($c);
    }

    /**
     * Fees the client still owes that may be netted from the money held: commission first (oldest order first), then storage when allowed.
     *
     * @return Collection<int, array{kind: string, label: string, invoice: Invoice, balance: int}>
     */
    public static function dues(Client $c): Collection
    {
        $rows = ClientOrder::where('fulfilment', 'stock')->where('status', 'successful')->whereNotNull('billed_invoice_id')
            ->whereHas('project', fn ($q) => $q->where('client_id', $c->id))->with('invoice')->orderBy('id')->get()
            ->filter(fn ($o) => $o->invoice && $o->invoice->balance() > 0)
            ->map(fn ($o) => ['kind' => 'commission', 'label' => 'Commission — order '.$o->reference, 'invoice' => $o->invoice, 'balance' => $o->invoice->balance()]);

        if (BusinessSetting::current()->storage_deduct) {
            $rows = $rows->concat(StorageCharge::where('client_id', $c->id)->where('status', 'billed')->whereNotNull('invoice_id')->with(['invoice', 'lot'])->orderBy('id')->get()
                ->filter(fn ($s) => $s->invoice && $s->invoice->balance() > 0)
                ->map(fn ($s) => ['kind' => 'storage', 'label' => 'Storage — '.$s->lot->number.' '.$s->period, 'invoice' => $s->invoice, 'balance' => $s->invoice->balance()]));
        }

        return $rows->values();
    }

    /** @return array<string, mixed> */
    public static function statement(Client $c): array
    {
        $held = self::fundsHeld($c);
        $dues = self::dues($c);
        $commission = (int) $dues->where('kind', 'commission')->sum('balance');
        $storage = (int) $dues->where('kind', 'storage')->sum('balance');
        $netting = min($held, $commission + $storage);
        $orders = ClientOrder::where('fulfilment', 'stock')->whereHas('project', fn ($q) => $q->where('client_id', $c->id))->with('items')->get();

        return [
            'delivered_sales' => (int) $orders->where('delivery_status', 'delivered')->sum(fn ($o) => $o->deliveredValue()),
            'collected' => self::collected($c), 'commission_due' => $commission, 'storage_due' => $storage,
            'storage_not_deductible' => BusinessSetting::current()->storage_deduct ? 0 : (int) StorageCharge::where('client_id', $c->id)->where('status', 'billed')->with('invoice')->get()->sum(fn ($s) => $s->invoice?->balance() ?? 0),
            'settled' => self::settledOut($c), 'funds_held' => $held, 'netting' => $netting, 'payable' => $held - $netting, 'dues' => $dues,
            'history' => ClientSettlement::where('client_id', $c->id)->latest('id')->get(),
        ];
    }

    /**
     * @return ClientSettlement|null null when this exact submission was already recorded
     */
    public static function record(Client $client, ?int $pay, string $date, ?string $reference, ?int $accountId, string $key, User $user): ?ClientSettlement
    {
        if (ClientSettlement::where('idempotency_key', $key)->exists()) {
            return null;
        }
        try {
            return DB::transaction(function () use ($client, $pay, $date, $reference, $accountId, $key, $user) {
                Client::whereKey($client->id)->lockForUpdate()->firstOrFail(); // one settlement per client at a time
                if (AccountingService::isClosed($date)) {
                    throw new RuntimeException('That month is already closed; record it in an open month.');
                }
                $held = self::fundsHeld($client);
                $dues = self::dues($client);
                $netting = min($held, (int) $dues->sum('balance'));
                $payable = $held - $netting;
                $pay ??= $payable;
                if ($pay < 0 || $pay > $payable) {
                    throw new RuntimeException('The most that can be paid to the client now is '.Money::format($payable).' (money held '.Money::format($held).', fees netted '.Money::format($netting).').');
                }
                if ($netting + $pay <= 0) {
                    throw new RuntimeException('There is nothing to settle: no collected money is held for this client.');
                }
                if ($pay > 0 && trim((string) $reference) === '') {
                    throw new RuntimeException('Enter the bank or transfer reference of the payment to the client.');
                }
                if ($pay > 0) {
                    AccountingService::assertCanSpend($accountId, $pay);
                }

                $settlement = ClientSettlement::create(['client_id' => $client->id, 'funds_before' => $held, 'paid' => $pay, 'paid_on' => $date, 'reference' => $reference,
                    'financial_account_id' => $accountId, 'idempotency_key' => $key, 'created_by' => $user->id]);

                $left = $netting;
                $byKind = ['commission' => 0, 'storage' => 0];
                foreach ($dues as $due) {
                    if ($left <= 0) {
                        break;
                    }
                    $take = min($left, $due['balance']);
                    $payment = app(PaymentRecorder::class)->save(new Payment, $due['invoice']->project, $due['invoice'], [
                        'amount' => $take, 'paid_on' => $date, 'method' => 'other', 'reference' => 'Netted from client sales proceeds (settlement #'.$settlement->id.')',
                        'notes' => $due['label'], 'idempotency_key' => (string) str()->uuid(),
                    ], null);
                    ReceiptClassification::create(['payment_id' => $payment->id, 'nature' => 'service', 'notes' => 'Fee netted from client sales proceeds', 'classified_by' => $user->id]);
                    $byKind[$due['kind']] += $take;
                    $left -= $take;
                }
                $settlement->update(['commission_netted' => $byKind['commission'], 'storage_netted' => $byKind['storage']]);

                // One balanced entry. The netted part never leaves the bank (it becomes company money); it is reclassified out of client funds
                // and the month-end close books the matching income. The remitted part is the only real cash out.
                Ledger::post($date, 'Client settlement #'.$settlement->id.' — '.$client->name, 'client_settlement', $settlement->id, [
                    ['client.funds', null, $netting + $pay, 0],
                    ['cash', null, 0, $netting],
                    ['cash', null, 0, $pay, AccountingService::activeAccountId($accountId)],
                ], $user->id);
                Audit::log('client.settled', 'client '.$client->id, "netted $netting, paid $pay, held after ".($held - $netting - $pay));

                return $settlement;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
