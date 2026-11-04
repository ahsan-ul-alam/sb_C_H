<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\ImpairedStockEvent;
use App\Models\InventoryHandover;
use App\Models\InventoryLossCase;
use App\Models\InventoryValueMovement;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The end of an impairment case: units held apart at a recoverable value R (an approved "impaired" or "repairable" case) are either
 *   release   repaired or found resalable: back into sellable stock and the line's cost layers at exactly the value they were held at.
 *             Dr assets.inventory / Cr assets.inventory_impaired (no gain, no loss: the value was already recognised).
 *   dispose   sold as salvage or scrapped: the units leave the warehouse; proceeds (if any) are cash on a named active account.
 *             Dr cash proceeds / Cr assets.inventory_impaired R, and the difference to the account the case's loss went to (inventory_loss for an abnormal loss,
 *             cogs for a normal one): a loss if proceeds are below R, a recovery if above. Proceeds above the ORIGINAL cost of those units are refused: inventory is
 *             never recovered above cost, and that case needs a Finance decision.
 * A request is made by Operations and decided by a different Board member; nothing is posted before. The share of the case's recoverable value and original cost belonging to
 * a quantity is the difference of two cumulative shares over the units already released or disposed of, so any sequence of partial events adds up exactly.
 */
class ImpairedStockService
{
    public static function used(InventoryLossCase $case, ?int $exceptId = null, bool $postedOnly = false): string
    {
        return bcadd((string) ImpairedStockEvent::where('inventory_loss_case_id', $case->id)->whereIn('status', $postedOnly ? ['posted'] : ['pending', 'posted'])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('quantity'), '0', 2);
    }

    /** @return array{recoverable: int, cost: int} the exact shares of the case's recoverable value and original cost for the next $quantity units */
    public static function shares(InventoryLossCase $case, string $quantity, ?int $exceptId = null): array
    {
        $before = self::used($case, $exceptId, true);
        $after = bcadd($before, $quantity, 2);
        $share = fn (int $value, string $units): int => bccomp($units, (string) $case->quantity, 2) === 0 ? $value : (int) bcadd(bcdiv(bcmul((string) $value, $units, 2), (string) $case->quantity, 6), '0.5', 0);

        return ['recoverable' => $share((int) $case->recoverable_value, $after) - $share((int) $case->recoverable_value, $before), 'cost' => $share((int) $case->carrying_value, $after) - $share((int) $case->carrying_value, $before)];
    }

    public static function propose(User $user, InventoryLossCase $case, string $action, string $quantity, string $eventOn, string $reference, string $evidence, int $proceeds, ?int $accountId, string $key): ImpairedStockEvent
    {
        if ($existing = ImpairedStockEvent::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($user, $case, $action, $quantity, $eventOn, $reference, $evidence, $proceeds, $accountId, $key) {
                SourcingLot::whereKey($case->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                $case = InventoryLossCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                if ($case->status !== 'approved' || ! in_array($case->outcome, ['impaired', 'repairable'], true) || $case->scope === 'custody') {
                    throw new RuntimeException('Only goods held apart by an approved impairment or repair case can be released or disposed of here.');
                }
                if (! in_array($action, ['release', 'dispose'], true)) {
                    throw new RuntimeException('Choose release to sellable stock, or disposal.');
                }
                if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $quantity) || bccomp($quantity, '0', 2) <= 0) {
                    throw new RuntimeException('Enter a quantity greater than zero.');
                }
                $room = bcsub((string) $case->quantity, self::used($case), 2);
                if (bccomp($quantity, $room, 2) > 0) {
                    throw new RuntimeException('Only '.(bccomp($room, '0', 2) > 0 ? $room : '0.00').' units of this case are still held apart and not already in a release or disposal.');
                }
                if (mb_strlen(trim($reference)) < 5 || mb_strlen(trim($evidence)) < 5) {
                    throw new RuntimeException($action === 'release' ? 'Give the repair completion or inspection report.' : 'Give the salvage invoice or destruction certificate, and the evidence.');
                }
                if ($eventOn > today()->toDateString() || $eventOn < $case->approved_at->toDateString()) {
                    throw new RuntimeException('The date cannot be in the future or before the case was approved.');
                }
                if ($proceeds < 0 || ($action === 'release' && $proceeds !== 0)) {
                    throw new RuntimeException($action === 'release' ? 'A release has no proceeds.' : 'Proceeds cannot be negative.');
                }
                if ($proceeds > 0 && $accountId === null && FinancialAccount::query()->exists()) {
                    throw new RuntimeException('Choose the financial account the proceeds were received into.');
                }
                AccountingService::assertActive($accountId);
                $event = ImpairedStockEvent::create(['inventory_loss_case_id' => $case->id, 'action' => $action, 'quantity' => $quantity, 'proceeds' => $proceeds, 'financial_account_id' => $accountId, 'reference' => trim($reference),
                    'evidence_reference' => trim($evidence), 'event_on' => $eventOn, 'prepared_by' => $user->id, 'idempotency_key' => $key]);
                Audit::log('impaired_stock.proposed', 'case '.$case->id, '#'.$event->id.' '.$action.' '.$quantity);

                return $event->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return ImpairedStockEvent::where('idempotency_key', $key)->firstOrFail();
        }
    }

    public static function decide(ImpairedStockEvent $event, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($event, $approve, $user, $note) {
            $case = InventoryLossCase::findOrFail(ImpairedStockEvent::findOrFail($event->id)->inventory_loss_case_id);
            $lot = SourcingLot::whereKey($case->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $case = InventoryLossCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $event = ImpairedStockEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($event->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve a request you made.');
            }
            if (! $user->can('inventory.loss.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide this.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $event->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);

                return;
            }
            if (JournalEntry::where('source_type', 'impaired_stock_event')->where('source_id', $event->id)->exists()) {
                throw new RuntimeException('This event has already been posted.');
            }
            $quantity = (string) $event->quantity;
            $line = $lot->lines()->findOrFail($case->sourcing_lot_line_id);
            if (bccomp($quantity, Inventory::sliceBalances($line, null)['damaged'], 2) > 0) {
                throw new RuntimeException('Those units are no longer held apart in the damaged stock.');
            }
            if (bccomp(bcadd(self::used($case, $event->id, true), $quantity, 2), (string) $case->quantity, 2) > 0) {
                throw new RuntimeException('More units than the case holds apart.');
            }
            $shares = self::shares($case, $quantity, $event->id);
            $r = $shares['recoverable'];
            $closed = AccountingService::isClosed($event->event_on->toDateString());
            $date = $closed ? today()->toDateString() : $event->event_on->toDateString();
            AccountingService::assertOpen($date, 'this inventory event');
            $event->update(['posted_on' => $date]);
            $suffix = $closed ? ' (event dated '.$event->event_on->toDateString().' in a closed period; recorded in the open period, flagged for review)' : '';
            $lossAccount = InventoryLossService::lossAccount($case);
            $lossDelta = 0;

            if ($event->action === 'release') {
                if ($r <= 0) {
                    throw new RuntimeException('There is no recoverable value to release for these units.');
                }
                $entry = Ledger::post($date, 'Impaired stock released to sellable stock at its carrying value (case #'.$case->id.', event #'.$event->id.')'.$suffix, 'impaired_stock_event', $event->id, [
                    ['assets.inventory', null, $r, 0], ['assets.inventory_impaired', null, 0, $r],
                ], $user->id);
                [$purchase, $landed] = self::split($case, $r);
                InventoryValuation::recordImpairedRelease($event, $case, $line, $purchase, $landed, $entry->id, $user);
                Inventory::move($line, 'adjust', ['damaged' => bcmul($quantity, '-1', 2), 'available' => $quantity], 'imp:'.$event->id, null, $user, 'Impaired stock released (case #'.$case->id.')');
            } else {
                $proceeds = (int) $event->proceeds;
                if ($proceeds > $shares['cost']) {
                    throw new RuntimeException('Proceeds ('.Money::format($proceeds).') are above the original cost of these units ('.Money::format($shares['cost']).'): inventory is never recovered above cost. Finance must decide how to treat it.');
                }
                $lossDelta = $r - $proceeds; // + loss, - recovery above the carrying value
                $lines = [];
                if ($proceeds > 0) {
                    $lines[] = ['cash', null, $proceeds, 0, AccountingService::activeAccountId($event->financial_account_id)];
                }
                if ($lossDelta > 0) {
                    $lines[] = [$lossAccount, null, $lossDelta, 0];
                } elseif ($lossDelta < 0) {
                    $lines[] = [$lossAccount, null, 0, -$lossDelta];
                }
                if ($r > 0) {
                    $lines[] = ['assets.inventory_impaired', null, 0, $r];
                }
                $entry = $lines === [] ? null : Ledger::post($date, 'Impaired stock disposed of (case #'.$case->id.', event #'.$event->id.', ref '.$event->reference.'), proceeds '.$proceeds.$suffix, 'impaired_stock_event', $event->id, $lines, $user->id);
                Inventory::move($line, 'adjust', ['damaged' => bcmul($quantity, '-1', 2)], 'imp:'.$event->id, null, $user, 'Impaired stock disposed of (case #'.$case->id.')');
            }
            $event->update(['status' => 'posted', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null, 'carrying_value' => $r, 'original_cost' => $shares['cost'],
                'loss_delta' => $lossDelta, 'loss_account' => $lossAccount, 'prior_period' => $closed, 'journal_entry_id' => $entry?->id]);
            Audit::log('impaired_stock.'.$event->action, 'case '.$case->id, $quantity.' units, carrying '.$r.', proceeds '.$event->proceeds);
        });
    }

    /**
     * Purchase and landed parts of a released value, in the proportions the case's units left the layers in (the quarantine movements), or, for a damaged return that never
     * left them, in the proportions of the handover's recorded cost.
     *
     * @return array{0: int, 1: int}
     */
    private static function split(InventoryLossCase $case, int $value): array
    {
        if ($case->scope === 'return') {
            $h = InventoryHandover::findOrFail($case->inventory_handover_id);
            [$p, $l] = [(int) $h->purchase_value, (int) $h->landed_value];
        } else {
            $moves = InventoryValueMovement::where('source_type', 'inventory_loss_case')->where('source_id', $case->id);
            $p = -(int) (clone $moves)->where('component', 'purchase')->sum('value_delta');
            $l = -(int) (clone $moves)->where('component', 'landed')->sum('value_delta');
        }
        $purchase = $p + $l > 0 ? (int) round($value * $p / ($p + $l)) : $value;

        return [$purchase, $value - $purchase];
    }
}
