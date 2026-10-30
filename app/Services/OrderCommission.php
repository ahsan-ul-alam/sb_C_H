<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Commission on successful client orders. It is billed through the client's existing segment + invoice, so it becomes
 * revenue only when ClientHub receives the payment (the existing rule) — there is no second revenue posting.
 * The rate is snapshotted when an order turns successful; later rate changes never touch earlier orders.
 * Base = agreed net order value (excluding shipping and taxes). Cancelled orders earn nothing.
 */
class OrderCommission
{
    public static function rateFor(Project $project): int
    {
        return (int) ($project->client->commission_bps ?? BusinessSetting::current()->commission_bps);
    }

    /** Half-up commission in minor units for a net value and a rate in basis points. */
    public static function commission(int $net, int $bps): int
    {
        return (int) bcadd(bcdiv(bcmul((string) $net, (string) $bps, 0), '10000', 4), '0.5', 0);
    }

    /**
     * @param  array{reference: string, ordered_on: string, net_value: int, notes?: ?string}  $data
     */
    public static function record(Project $project, array $data, User $user): ClientOrder
    {
        if ($data['net_value'] <= 0) {
            throw new RuntimeException('The net order value must be greater than zero.');
        }
        try {
            $order = ClientOrder::create([...$data, 'project_id' => $project->id, 'created_by' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('That order reference is already recorded for this project.');
        }
        Audit::log('order.recorded', 'order '.$order->id, $data['reference'].' '.$data['net_value']);

        return $order;
    }

    private static function locked(ClientOrder $order, \Closure $work): mixed
    {
        return DB::transaction(fn () => $work(ClientOrder::whereKey($order->id)->lockForUpdate()->with('project.client')->firstOrFail()));
    }

    public static function markSuccessful(ClientOrder $order): void
    {
        self::locked($order, function (ClientOrder $o) {
            if ($o->status !== 'pending') {
                throw new RuntimeException('Only a pending order can be marked successful.');
            }
            $rate = self::rateFor($o->project);
            $o->update(['status' => 'successful', 'rate_bps' => $rate, 'commission' => self::commission($o->net_value, $rate)]);
            Audit::log('order.successful', 'order '.$o->id, 'rate '.$rate.' commission '.$o->commission);

            // Under the Board's standing policy the commission is invoiced at once, through the client's normal billing.
            if ($o->commission > 0 && ($policy = StandingPolicies::active(StandingPolicies::COMMISSION))) {
                [$invoice] = ClientBilling::addCharge($o->project, 'Order commission', 'Commission on successful orders', null, null, 'Order commission',
                    'Commission on order '.$o->reference, '1', null, $o->commission, $o->commission);
                $o->update(['billed_invoice_id' => $invoice->id, 'billed_at' => now()]);
                Audit::log('order.billed', 'order '.$o->id, 'SYSTEM under standing policy #'.$policy->id.': '.$invoice->number.' '.$o->commission);
            }
        });
    }

    /**
     * Correct a commission that is already invoiced, without rewriting history silently. Only while nothing has been
     * received against it: the invoice line, its segment and the invoice total are reduced (or the line removed for a
     * cancellation) with the old and new figures written to the audit log. Once the client has paid, the money must be
     * returned through the existing refund process (Advances & Refunds) — this refuses rather than guess.
     * $newNet = null cancels the order's commission entirely.
     */
    public static function reduceInvoiced(ClientOrder $order, ?int $newNet, string $reason): void
    {
        self::locked($order, function (ClientOrder $o) use ($newNet, $reason) {
            if (! $o->billed_invoice_id || $o->status === 'cancelled') {
                throw new RuntimeException('This order\'s commission is not invoiced.');
            }
            if ($newNet !== null && ($newNet <= 0 || $newNet >= $o->net_value)) {
                throw new RuntimeException('Enter a lower, positive net value, or cancel the commission.');
            }
            $invoice = Invoice::whereKey($o->billed_invoice_id)->lockForUpdate()->firstOrFail();
            $segmentIds = $invoice->items()->whereNotNull('segment_id')->pluck('segment_id')->unique()->all();
            $item = $invoice->items()->where('description', 'Commission on order '.$o->reference)->where('amount', $o->commission)->first();
            if (! $item || $invoice->amount_paid > 0 || $invoice->payments()->exists() || $invoice->segmentPaymentsCredit() > 0) {
                throw new RuntimeException('Money has already been received on this invoice (or its line was changed). Return it through Advances & Refunds, then adjust the invoice there.');
            }
            $new = $newNet === null ? 0 : self::commission($newNet, (int) $o->rate_bps);
            $cut = $o->commission - $new;
            DB::table('segments')->where('id', $item->segment_id)->decrement('amount', $cut);
            if ($new > 0) {
                $item->update(['unit_price' => $new, 'amount' => $new]);
            } else {
                $item->delete();
            }
            $invoice->total -= $cut;
            $invoice->refreshPaymentStatus();
            Invoice::refreshBillingSegments($segmentIds, $invoice->id);
            $number = $invoice->number;
            if ($invoice->items()->doesntExist()) {
                $invoice->delete(); // an invoice that held only this commission and nothing received: nothing left to keep
            }
            $before = $o->commission;
            $o->update($newNet === null ? ['status' => 'cancelled', 'commission' => 0, 'net_value' => $o->net_value] : ['net_value' => $newNet, 'commission' => $new]);
            Audit::log('order.invoiced_commission_reduced', 'order '.$o->id, $number.': commission '.$before.' -> '.$new.' ('.$reason.')');
        });
    }

    public static function cancel(ClientOrder $order): void
    {
        self::locked($order, function (ClientOrder $o) {
            self::assertUnbilled($o);
            if ($o->status === 'cancelled') {
                throw new RuntimeException('This order is already cancelled.');
            }
            $o->update(['status' => 'cancelled', 'commission' => 0]);
            Audit::log('order.cancelled', 'order '.$o->id);
        });
    }

    /** A refund or partial cancellation before billing lowers the net value; the commission follows the snapshot rate. */
    public static function adjustNet(ClientOrder $order, int $newNet): void
    {
        self::locked($order, function (ClientOrder $o) use ($newNet) {
            self::assertUnbilled($o);
            if ($o->status === 'cancelled' || $newNet <= 0 || $newNet >= $o->net_value) {
                throw new RuntimeException('Enter a lower, positive net value for an open order (cancel the order to remove it entirely).');
            }
            $o->update(['net_value' => $newNet, 'commission' => $o->status === 'successful' ? self::commission($newNet, (int) $o->rate_bps) : 0]);
            Audit::log('order.adjusted', 'order '.$o->id, 'net '.$newNet);
        });
    }

    public static function bill(ClientOrder $order, ?int $segmentId, ?int $invoiceId): Invoice
    {
        return self::locked($order, function (ClientOrder $o) use ($segmentId, $invoiceId) {
            if ($o->status !== 'successful' || $o->commission <= 0) {
                throw new RuntimeException('Only a successful order with a commission above zero can be billed.');
            }
            self::assertUnbilled($o);
            [$invoice] = ClientBilling::addCharge($o->project, 'Order commission', 'Commission on successful orders', $segmentId, $invoiceId, 'Order commission',
                'Commission on order '.$o->reference, '1', null, $o->commission, $o->commission);
            $o->update(['billed_invoice_id' => $invoice->id, 'billed_at' => now()]);
            Audit::log('order.billed', 'order '.$o->id, $invoice->number.' '.$o->commission);

            return $invoice;
        });
    }

    private static function assertUnbilled(ClientOrder $o): void
    {
        if ($o->billed_invoice_id) {
            throw new RuntimeException('This order\'s commission is already invoiced; correct it through the invoice and a recorded refund, not here.');
        }
    }

    public static function setDefaultRate(int $bps): void
    {
        $setting = BusinessSetting::current();
        $setting->update(['commission_bps' => $bps]);
        Audit::log('commission.default_rate', 'settings', 'from '.$setting->getOriginal('commission_bps').' to '.$bps);
    }

    public static function setClientRate(Client $client, ?int $bps): void
    {
        $client->commission_bps = $bps;
        $client->save();
        Audit::log('commission.client_rate', 'client '.$client->id, $bps === null ? 'default' : (string) $bps);
    }
}
