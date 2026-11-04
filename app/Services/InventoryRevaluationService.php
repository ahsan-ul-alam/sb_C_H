<?php

namespace App\Services;

use App\Models\InventoryRevaluation;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Write a lot line's stock down to its net realisable value, or reverse an earlier write-down. Inventory is never carried above cost, so a reversal can only give back
 * what earlier approved write-downs of the same line took, net of earlier reversals. Quantity does not change; the exact integer amount is appended to the line's cost
 * layers as a revaluation movement and posted once:
 *      write-down   Dr inventory_writedown / Cr assets.inventory
 *      reversal     Dr assets.inventory / Cr inventory_writedown
 * Maker-checker in three: Operations or Finance proposes with the evidence of the net realisable value, a Finance reviewer who did not propose it checks, and a Board member
 * who did neither approves, whatever the amount. A write-down cannot exceed the value the line holds, and cannot be made while units of it are in an open supplier return,
 * loss case or handover request that would be valued off the old figure.
 */
class InventoryRevaluationService
{
    public static function netWrittenDown(SourcingLotLine $line): int
    {
        $rows = InventoryRevaluation::where('sourcing_lot_line_id', $line->id)->where('status', 'approved')->get();

        return (int) $rows->where('direction', 'write_down')->sum('amount') - (int) $rows->where('direction', 'reversal')->sum('amount');
    }

    public static function propose(User $user, SourcingLotLine $line, string $direction, int $amount, string $basis, string $evidence, string $effectiveOn, string $key): InventoryRevaluation
    {
        if ($existing = InventoryRevaluation::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($user, $line, $direction, $amount, $basis, $evidence, $effectiveOn, $key) {
                $lot = SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                $line = SourcingLotLine::whereKey($line->id)->firstOrFail();
                if ($lot->ownership !== 'company' || ! InventoryEngine::isValued($lot)) {
                    throw new RuntimeException('Only Sellbees-owned stock that the inventory engine values can be revalued.');
                }
                if (! in_array($direction, ['write_down', 'reversal'], true) || $amount <= 0) {
                    throw new RuntimeException('Choose a write-down or a reversal, and an amount above zero.');
                }
                if (mb_strlen(trim($basis)) < 5 || mb_strlen(trim($evidence)) < 5) {
                    throw new RuntimeException('State the net realisable value and its evidence (a price list, a sales quote, a market report).');
                }
                if ($effectiveOn > today()->toDateString()) {
                    throw new RuntimeException('The date cannot be in the future.');
                }
                if (InventoryRevaluation::where('sourcing_lot_line_id', $line->id)->whereIn('status', ['pending_review', 'reviewed'])->exists()) {
                    throw new RuntimeException('This line already has a revaluation awaiting a decision.');
                }
                self::assertAmount($line, $direction, $amount);
                $r = InventoryRevaluation::create(['sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'direction' => $direction, 'amount' => $amount, 'basis' => trim($basis),
                    'evidence_reference' => trim($evidence), 'effective_on' => $effectiveOn, 'prepared_by' => $user->id, 'idempotency_key' => $key]);
                Audit::log('inventory.revaluation_proposed', 'line '.$line->id, '#'.$r->id.' '.$direction.' '.$amount);

                return $r->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return InventoryRevaluation::where('idempotency_key', $key)->firstOrFail();
        }
    }

    private static function assertAmount(SourcingLotLine $line, string $direction, int $amount): void
    {
        $f = InventoryValuation::lineFigures($line);
        InventoryValuation::assertIssuable($line);
        if ($direction === 'write_down' && $amount > $f['purchase'] + $f['landed']) {
            throw new RuntimeException('A write-down cannot exceed the value the line holds ('.Money::format($f['purchase'] + $f['landed']).').');
        }
        if ($direction === 'write_down' && bccomp($f['quantity'], '0', 2) <= 0) {
            throw new RuntimeException('There are no valued units on this line to write down.');
        }
        if ($direction === 'reversal' && $amount > self::netWrittenDown($line)) {
            throw new RuntimeException('A reversal cannot give back more than earlier approved write-downs took ('.Money::format(max(0, self::netWrittenDown($line))).'): inventory is never carried above cost.');
        }
    }

    public static function review(InventoryRevaluation $revaluation, User $user, string $note): void
    {
        DB::transaction(function () use ($revaluation, $user, $note) {
            $r = InventoryRevaluation::whereKey($revaluation->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending_review') {
                throw new RuntimeException('This revaluation is not waiting for review.');
            }
            if (! $user->can('inventory.loss.review')) {
                throw new RuntimeException('Only Finance can review a revaluation.');
            }
            if ($r->prepared_by === $user->id) {
                throw new RuntimeException('You cannot review a revaluation you proposed.');
            }
            if (trim($note) === '') {
                throw new RuntimeException('Record what Finance checked.');
            }
            $r->update(['status' => 'reviewed', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => trim($note)]);
        });
    }

    public static function decide(InventoryRevaluation $revaluation, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($revaluation, $approve, $user, $note) {
            $lot = SourcingLot::whereKey(InventoryRevaluation::findOrFail($revaluation->id)->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $r = InventoryRevaluation::whereKey($revaluation->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'reviewed') {
                throw new RuntimeException(in_array($r->status, ['approved', 'rejected'], true) ? 'Already decided.' : 'Finance must review this before the Board can decide it.');
            }
            if ($r->prepared_by === $user->id || $r->reviewed_by === $user->id) {
                throw new RuntimeException('You cannot approve a revaluation you proposed or reviewed.');
            }
            if (! $user->can('inventory.loss.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide a revaluation.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $r->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);

                return;
            }
            if (JournalEntry::where('source_type', 'inventory_revaluation')->where('source_id', $r->id)->exists()) {
                throw new RuntimeException('This revaluation has already been posted.');
            }
            $line = $lot->lines()->findOrFail($r->sourcing_lot_line_id);
            self::assertAmount($line, $r->direction, (int) $r->amount);
            $closed = AccountingService::isClosed($r->effective_on->toDateString());
            $date = $closed ? today()->toDateString() : $r->effective_on->toDateString();
            AccountingService::assertOpen($date, 'this revaluation');
            $r->update(['posted_on' => $date]);
            $amount = (int) $r->amount;
            $memo = 'Inventory '.str_replace('_', ' ', $r->direction).' to net realisable value (revaluation #'.$r->id.'): '.$r->basis.($closed ? ' - effective '.$r->effective_on->toDateString().' in a closed period; recorded in the open period, flagged for review' : '');
            $entry = Ledger::post($date, $memo, 'inventory_revaluation', $r->id, $r->direction === 'write_down'
                ? [['inventory_writedown', null, $amount, 0], ['assets.inventory', null, 0, $amount]]
                : [['assets.inventory', null, $amount, 0], ['inventory_writedown', null, 0, $amount]], $user->id);
            InventoryValuation::recordRevaluation($r, $line, $r->direction === 'write_down' ? -$amount : $amount, $entry->id, $user);
            $r->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null, 'prior_period' => $closed, 'journal_entry_id' => $entry->id]);
            Audit::log('inventory.revaluation_approved', 'line '.$line->id, $r->direction.' '.$amount);
        });
    }
}
