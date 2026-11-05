<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientOrder;
use App\Models\CommissionAdjustment;
use App\Models\OrderCollection;
use App\Models\OrderRefund;
use App\Models\OrderRefundAllocation;
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
 * It never uses Sellbees's own cash, and it never creates or reverses revenue or COGS. Gross merchandise value, client funds, earned commission and Sellbees
 * expenses stay apart: a refund moves client funds; only a classification that lowers the contractual commission base lowers the commission.
 *
 *   requested (allocated to collections) -> approved (a different person) -> paid (account, reference, once; commission corrected once, if due)
 *
 * Refundable = collected - refunds requested, approved or paid; every refund is allocated to the collection(s) it is paid back against, per collection capped.
 */
class OrderRefunds
{
    /**
     * classification => [label, who bears it, commission treatment]. Treatment: keep = the commission base is unchanged (cash moved, not the sale);
     * reduce = the approved amount lowers the commission base; pending = unknown, the approver must decide. A refund Sellbees must bear is blocked here.
     */
    public const CLASSES = [
        'return_of_goods' => ['Return of goods', 'client', 'keep'],            // the return already lowered the delivered value and so the commission
        'sale_cancellation' => ['Commercial sale cancellation', 'client', 'reduce'],
        'price_reduction' => ['Price reduction', 'client', 'reduce'],
        'goodwill' => ['Customer goodwill compensation', 'client', 'keep'],
        'delivery_fee' => ['Delivery-fee refund', 'client', 'keep'],           // delivery is outside the commission base
        'service_failure' => ['Sellbees service failure', 'sellbees', 'pending'],
        'other' => ['Other (Finance review)', 'client', 'pending'],
    ];

    public static function committed(ClientOrder $o): int
    {
        return (int) OrderRefund::where('client_order_id', $o->id)->whereIn('status', ['requested', 'approved', 'paid'])->sum('amount');
    }

    public static function paid(ClientOrder $o): int
    {
        return (int) OrderRefund::where('client_order_id', $o->id)->where('status', 'paid')->sum('amount');
    }

    /** Paid refunds that lowered the commission base (their commission correction is already applied, so a recalculation must keep honouring them). */
    public static function baseReduction(ClientOrder $o): int
    {
        return (int) OrderRefund::where('client_order_id', $o->id)->where('status', 'paid')->where('commission_treatment', 'reduce')->sum('amount');
    }

    public static function refundable(ClientOrder $o): int
    {
        return max(0, (int) $o->collections()->sum('amount') - self::committed($o));
    }

    /** What is still free to refund on one collection (its amount less the allocations of refunds that are not rejected or cancelled). */
    public static function collectionRoom(OrderCollection $c): int
    {
        $taken = (int) OrderRefundAllocation::where('order_collection_id', $c->id)
            ->whereHas('refund', fn ($q) => $q->whereIn('status', ['requested', 'approved', 'paid']))->sum('amount');

        return max(0, (int) $c->amount - $taken);
    }

    public static function request(ClientOrder $order, int $amount, string $classification, string $reason, ?string $beneficiary, User $user): OrderRefund
    {
        if (! isset(self::CLASSES[$classification]) || trim($reason) === '' || $amount <= 0) {
            throw new RuntimeException('Enter the amount, the classification of the refund, and the reason.');
        }

        return DB::transaction(function () use ($order, $amount, $classification, $reason, $beneficiary, $user) {
            $o = ClientOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $o->isStock() || $o->delivery_status !== 'delivered') {
                throw new RuntimeException('Only money collected on a delivered order can be refunded.');
            }
            $max = self::refundable($o);
            if ($amount > $max) {
                throw new RuntimeException('At most '.Money::format($max).' can still be refunded on this order (collected '.Money::format((int) $o->collections()->sum('amount')).', already claimed '.Money::format(self::committed($o)).').');
            }
            [, $responsible, $treatment] = self::CLASSES[$classification];
            $refund = OrderRefund::create(['client_order_id' => $o->id, 'amount' => $amount, 'kind' => $classification === 'return_of_goods' ? 'return' : 'no_return', 'classification' => $classification,
                'reason' => trim($reason), 'beneficiary' => trim((string) $beneficiary) ?: ($o->customer_name ?: null), 'responsible_party' => $responsible, 'commission_treatment' => $treatment, 'requested_by' => $user->id]);
            // Allocate to the oldest collections first, each capped at what it still has free; the parts add up exactly to the refund.
            $left = $amount;
            foreach ($o->collections()->get() as $collection) {
                $take = min($left, self::collectionRoom($collection));
                if ($take > 0) {
                    OrderRefundAllocation::create(['order_refund_id' => $refund->id, 'order_collection_id' => $collection->id, 'amount' => $take]);
                    $left -= $take;
                }
                if ($left === 0) {
                    break;
                }
            }
            if ($left > 0) {
                throw new RuntimeException('The collections of this order cannot cover that refund; nothing was requested.');
            }
            Audit::log('refund.requested', 'order '.$o->id, $amount.' '.$classification);

            return $refund;
        });
    }

    public static function decide(OrderRefund $refund, bool $approve, ?string $note, User $user, ?string $treatment = null, ?string $responsible = null): void
    {
        DB::transaction(function () use ($refund, $approve, $note, $user, $treatment, $responsible) {
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
            $changes = [];
            if ($approve) {
                if ($r->classification !== 'return_of_goods' && trim((string) $note) === '') {
                    throw new RuntimeException('A refund without returned goods needs its contractual justification in the decision note.');
                }
                if ($r->classification === 'other') {
                    if (! in_array($treatment, ['reduce', 'keep'], true) || ! in_array($responsible, ['client', 'sellbees'], true)) {
                        throw new RuntimeException('For an "Other" refund, Finance must state the commission treatment (reduce or keep) and who bears the refund.');
                    }
                    $changes = ['commission_treatment' => $treatment, 'responsible_party' => $responsible];
                }
            }
            $r->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note ? trim($note) : null] + $changes);
            if (! $approve) {
                OrderRefundAllocation::where('order_refund_id', $r->id)->delete(); // the collections' room is released
            }
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
                if ($r->responsible_party !== 'client') {
                    throw new RuntimeException('This refund is borne by Sellbees (a service failure), which is a company expense and not a refund from the client\'s funds. It is blocked here until Finance records the expense.');
                }
                if ((int) OrderRefundAllocation::where('order_refund_id', $r->id)->sum('amount') !== $r->amount) {
                    throw new RuntimeException('The refund is not fully allocated to collections; it cannot be paid.');
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
                self::correctCommission($r->refresh(), $o, $user);

                return $r;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * The one commission correction of a refund whose treatment is "reduce". It goes through OrderCommission::reduceInvoiced, which refuses once the commission was
     * paid or netted; then the refund is marked "review" and the commission is left untouched for a manual, evidenced correction. The unique adjustment row per refund
     * makes a second reversal impossible.
     */
    private static function correctCommission(OrderRefund $r, ClientOrder $order, User $user): void
    {
        if ($r->commission_treatment !== 'reduce' || CommissionAdjustment::where('order_refund_id', $r->id)->exists()) {
            return;
        }
        $o = ClientOrder::whereKey($order->id)->firstOrFail();
        if ($o->commission <= 0) {
            return;
        }
        $before = (int) $o->commission;
        $newNet = $o->net_value - $r->amount;
        try {
            if ($o->billed_invoice_id) {
                OrderCommission::reduceInvoiced($o, $newNet > 0 ? $newNet : null, 'Refund #'.$r->id.' ('.$r->classification.')');
            } elseif ($newNet > 0) {
                OrderCommission::adjustNet($o, $newNet);
            } else {
                OrderCommission::cancel($o);
            }
        } catch (RuntimeException $e) {
            $r->update(['commission_treatment' => 'review']);
            Audit::log('refund.commission_review', 'refund '.$r->id, $e->getMessage());

            return;
        }
        $o->refresh();
        CommissionAdjustment::create(['client_order_id' => $o->id, 'order_refund_id' => $r->id, 'commission_before' => $before, 'commission_after' => (int) $o->commission,
            'basis' => self::CLASSES[$r->classification][0].': the approved refund of '.$r->amount.' lowers the commission base from '.($newNet + $r->amount).' to '.max(0, $newNet), 'applied_by' => $user->id]);
        Audit::log('refund.commission_corrected', 'refund '.$r->id, $before.' -> '.$o->commission);
    }

    public static function cancel(OrderRefund $refund): void
    {
        if (! in_array($refund->status, ['requested', 'approved'], true)) {
            throw new RuntimeException('Only an open refund can be cancelled.');
        }
        $refund->update(['status' => 'cancelled']);
        OrderRefundAllocation::where('order_refund_id', $refund->id)->delete();
        Audit::log('refund.cancelled', 'refund '.$refund->id);
    }
}
