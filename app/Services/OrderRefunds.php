<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\OrderRefund;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refunds of end-customer money for a client's goods. The money was collected for the client and sits in "client funds", so a refund returns it from there:
 *
 *   payout  Dr client.funds / Cr cash (the account actually used)
 *
 * It never uses Sellbees's own cash beyond what the client's funds hold, and it never creates or reverses revenue, commission or COGS (the return of goods
 * already reduces the delivered value and the commission through OrderFulfilment::customerReturn). A refund the client owes Sellbees or Sellbees owes the
 * client is a different thing (credit notes and settlements) and is not handled here.
 *
 *   requested -> approved (a different person; a refund with no goods returned also needs the contractual justification) -> paid (account, reference, once)
 *
 * Refundable = collected - refunds already requested, approved or paid, so the same money can never be claimed twice.
 */
class OrderRefunds
{
    public static function committed(ClientOrder $o): int
    {
        return (int) OrderRefund::where('client_order_id', $o->id)->whereIn('status', ['requested', 'approved', 'paid'])->sum('amount');
    }

    public static function paid(ClientOrder $o): int
    {
        return (int) OrderRefund::where('client_order_id', $o->id)->where('status', 'paid')->sum('amount');
    }

    public static function refundable(ClientOrder $o): int
    {
        return max(0, (int) $o->collections()->sum('amount') - self::committed($o));
    }

    public static function request(ClientOrder $order, int $amount, string $kind, string $reason, User $user): OrderRefund
    {
        if (! in_array($kind, ['return', 'no_return'], true) || trim($reason) === '' || $amount <= 0) {
            throw new RuntimeException('Enter the amount, whether goods are coming back, and the reason.');
        }

        return DB::transaction(function () use ($order, $amount, $kind, $reason, $user) {
            $o = ClientOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $o->isStock() || $o->delivery_status !== 'delivered') {
                throw new RuntimeException('Only money collected on a delivered order can be refunded.');
            }
            $max = self::refundable($o);
            if ($amount > $max) {
                throw new RuntimeException('At most '.Money::format($max).' can still be refunded on this order (collected '.Money::format((int) $o->collections()->sum('amount')).', already claimed '.Money::format(self::committed($o)).').');
            }
            $refund = OrderRefund::create(['client_order_id' => $o->id, 'amount' => $amount, 'kind' => $kind, 'reason' => trim($reason), 'requested_by' => $user->id]);
            Audit::log('refund.requested', 'order '.$o->id, $amount.' '.$kind);

            return $refund;
        });
    }

    public static function decide(OrderRefund $refund, bool $approve, ?string $note, User $user): void
    {
        DB::transaction(function () use ($refund, $approve, $note, $user) {
            $r = OrderRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'requested') {
                throw new RuntimeException('This refund is already '.$r->status.'.');
            }
            if ($r->requested_by === $user->id) {
                throw new RuntimeException('A refund must be decided by someone other than the person who requested it.');
            }
            if (! $approve && trim((string) $note) === '') {
                throw new RuntimeException('Give the reason for rejecting the refund.');
            }
            if ($approve && $r->kind === 'no_return' && trim((string) $note) === '') {
                throw new RuntimeException('A refund without returned goods needs the contractual justification in the decision note.');
            }
            $r->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note ? trim($note) : null]);
            Audit::log('refund.'.($approve ? 'approved' : 'rejected'), 'refund '.$r->id, (string) $r->amount);
        });
    }

    /** @return OrderRefund|null null when this exact submission was already recorded */
    public static function pay(OrderRefund $refund, string $date, ?int $accountId, string $reference, string $key, User $user): ?OrderRefund
    {
        if (OrderRefund::where('idempotency_key', $key)->exists()) {
            return null;
        }
        if (trim($reference) === '') {
            throw new RuntimeException('Enter the transaction reference of the refund payment.');
        }
        try {
            return DB::transaction(function () use ($refund, $date, $accountId, $reference, $key, $user) {
                $r = OrderRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
                if ($r->status !== 'approved') {
                    throw new RuntimeException('Only an approved refund can be paid (this one is '.$r->status.').');
                }
                $o = ClientOrder::whereKey($r->client_order_id)->with('project')->firstOrFail();
                $client = Client::whereKey($o->project->client_id)->lockForUpdate()->firstOrFail(); // the same lock a settlement takes
                AccountingService::assertOpen($date, 'a refund payment');
                $held = ClientSettlementService::fundsHeld($client);
                if ($r->amount > $held) {
                    throw new RuntimeException('Only '.Money::format(max(0, $held)).' is held for this client; Sellbees\'s own cash is not used to refund a client\'s customer. Settle this with the client first.');
                }
                AccountingService::assertCanSpend($accountId, $r->amount);
                $account = AccountingService::activeAccountId($accountId);
                $r->update(['status' => 'paid', 'financial_account_id' => $account, 'paid_on' => $date, 'reference' => trim($reference), 'idempotency_key' => $key, 'paid_by' => $user->id]);
                Ledger::post($date, 'Customer refund — order '.$o->reference, 'order_refund', $r->id, [
                    ['client.funds', null, $r->amount, 0],
                    ['cash', null, 0, $r->amount, $account],
                ], $user->id);
                Audit::log('refund.paid', 'refund '.$r->id, $r->amount.' ref '.trim($reference));

                return $r;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public static function cancel(OrderRefund $refund): void
    {
        if (! in_array($refund->status, ['requested', 'approved'], true)) {
            throw new RuntimeException('Only an open refund can be cancelled.');
        }
        $refund->update(['status' => 'cancelled']);
        Audit::log('refund.cancelled', 'refund '.$refund->id);
    }
}
