<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\InventoryLossCase;
use App\Models\JournalEntry;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Models\SupplierPayment;
use App\Models\SupplierRecoveryDocument;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnSettlement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Goods sent back to a supplier. Four things are kept apart, because each is a different fact with a different proof:
 *
 *   physical return      the goods left (request, with the supplier's or carrier's return document)
 *   supplier acceptance  the supplier agrees to give something back, and says so in writing (credit note number or written acceptance)
 *   recognised claim     only what is accepted AND allocated is recognised, once, as a reduction of what we owe, a credit note, or a receivable
 *   cash                 only an actual receipt, on a named active account, settles a receivable
 *
 *   propose -> record acceptance (Finance) -> verify and allocate (Finance, not the proposer) -> approve (a Board member, neither of the two) -> later settlements
 *
 * Approval of goods that are in Sellbees inventory posts ONE journal entry for the exact value taken out of the line's cost layers, C:
 *      Cr assets.inventory C
 *      Dr liabilities P            reduction of an unpaid supplier payable the return legally reduces
 *      Dr supplier.credit K        an enforceable credit note (not cash, not income)
 *      Dr assets.supplier_receivable R   a monetary refund owed and accepted
 *      Dr supplier_return_difference X   what is NOT recovered (non-refundable freight, restocking charges, a disputed amount): C - P - K - R, with its stated reason
 * so the entry always balances and the inventory is never forced to match a recovery. A recovery above C is not posted: there is no supported treatment for a gain
 * on return, so it blocks. Later: a credit applied to a payable (Dr liabilities / Cr supplier.credit) and a cash refund (Dr cash / Cr supplier receivable).
 * Rejected goods that never entered inventory ("unaccepted") only record the claim: there is no inventory to reverse, and the supplier's advance refund or credit is
 * handled where it already is (the supplier payment's own refund and credit-note correction), never a second time here.
 */
class SupplierReturnService
{
    private const EVIDENCE_MIN = 5;

    /**
     * Why cost that was removed from inventory was not recovered from the supplier, and where each class is proposed to land in the operational P&L (Phase 2E).
     * Today every class is posted to the one account supplier_return_difference, which no profit report reads, so frozen distributable profit does not move;
     * the class is stored on the return so the later mapping needs no guesswork and no reclassification of history.
     */
    public const DIFFERENCE_CLASSES = [
        'nonrefundable_freight' => ['label' => 'Non-refundable freight or handling', 'limit' => 'landed', 'proposed_2e' => 'Operating expense: inbound freight and handling not recovered (cost of sourcing)'],
        'unrecoverable_cost' => ['label' => 'Unrecoverable inventory cost', 'limit' => null, 'proposed_2e' => 'Cost of goods sold: returned goods the supplier will not pay for (restocking charge, goods not accepted back)'],
        'recovery_shortfall' => ['label' => 'Supplier recovery shortfall', 'limit' => null, 'proposed_2e' => 'Other operating expense: supplier agreed to a lower recovery than the cost carried'],
        'disputed_claim' => ['label' => 'Disputed or pending claim', 'limit' => null, 'proposed_2e' => 'Receivable under dispute once recognition criteria are met; until then an expense, reversed (not re-posted) if the claim is later won'],
        'accounting_correction' => ['label' => 'Accounting correction', 'limit' => null, 'proposed_2e' => 'Prior-period or correction line reviewed by Finance and the Board; never an ordinary expense'],
    ];

    // ── Reading ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Units of a line already in an open supplier return, or in an open loss case on its sellable stock. */
    public static function committedUnits(SourcingLotLine $line, ?int $exceptId = null): string
    {
        $returns = SupplierReturn::where('sourcing_lot_line_id', $line->id)->where('kind', 'accepted')->whereIn('status', SupplierReturn::ACTIVE)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('quantity');
        $cases = InventoryLossCase::where('sourcing_lot_line_id', $line->id)->whereIn('scope', ['owned'])->where('from_bucket', 'available')->whereIn('status', InventoryLossCase::ACTIVE)->sum('quantity');

        return bcadd(bcadd((string) $returns, (string) $cases, 2), '0', 2);
    }

    /** What approved returns and posted credit applications have already taken off the payable the lot owes this supplier. */
    public static function payableReduced(SourcingLot $lot, int $supplierId): int
    {
        $returns = SupplierReturn::where('sourcing_lot_id', $lot->id)->where('supplier_id', $supplierId)->where('status', 'approved');
        $applied = SupplierReturnSettlement::where('method', 'credit_applied')->where('status', 'posted')
            ->whereIn('supplier_return_id', SupplierReturn::where('sourcing_lot_id', $lot->id)->where('supplier_id', $supplierId)->select('id'))->sum('amount');

        return (int) (clone $returns)->sum('payable_reduction') + (int) $applied;
    }

    /**
     * The recovery categories of one return, each separate: nothing proposed or disputed is ever shown as collected.
     *
     * @return array{claimed: int, accepted: int, disputed: int, unallocated: int, payable_reduction: int, credit_total: int, credit_applied: int, credit_pending: int, credit_open: int, credit_available: int,
     *               receivable_total: int, cash_received: int, cash_pending: int, receivable_open: int, receivable_available: int, difference: int}
     */
    public static function balances(SupplierReturn $r): array
    {
        $approved = $r->status === 'approved';
        $sum = fn (string $method, string $status) => (int) $r->settlements()->where('method', $method)->where('status', $status)->sum('amount');
        $credit = $approved ? (int) $r->credit_note_amount : 0;
        $receivable = $approved ? (int) $r->receivable_amount : 0;
        $creditOpen = $credit - $sum('credit_applied', 'posted');
        $receivableOpen = $receivable - $sum('cash_refund', 'posted');
        $accepted = (int) ($r->accepted_amount ?? 0);

        return ['claimed' => (int) $r->claimed_amount, 'accepted' => $accepted, 'disputed' => max(0, (int) $r->claimed_amount - $accepted),
            'unallocated' => $approved ? max(0, $accepted - (int) $r->payable_reduction - (int) $r->credit_note_amount - (int) $r->receivable_amount) : 0,
            'payable_reduction' => $approved ? (int) $r->payable_reduction : 0, 'credit_total' => $credit, 'credit_applied' => $sum('credit_applied', 'posted'), 'credit_pending' => $sum('credit_applied', 'pending'),
            'credit_open' => $creditOpen, 'credit_available' => $creditOpen - $sum('credit_applied', 'pending'),
            'receivable_total' => $receivable, 'cash_received' => $sum('cash_refund', 'posted'), 'cash_pending' => $sum('cash_refund', 'pending'),
            'receivable_open' => $receivableOpen, 'receivable_available' => $receivableOpen - $sum('cash_refund', 'pending'), 'difference' => $approved ? (int) $r->difference_value : 0];
    }

    private static function assertSellbeesStock(SourcingLot $lot): void
    {
        if ($lot->ownership !== 'company' || ! InventoryEngine::isValued($lot)) {
            throw new RuntimeException('Only Sellbees-owned stock that the inventory engine values can be returned to a supplier from inventory; client-owned goods are never consumed here.');
        }
    }

    // ── Request ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{kind: string, quantity: string, reason: string, return_reference: string, physical_returned_on: string, evidence_reference: string, claimed_amount?: int, expected_method?: ?string}  $data
     */
    public static function propose(User $user, SourcingLotLine $line, array $data, string $key): SupplierReturn
    {
        if ($existing = SupplierReturn::where('idempotency_key', $key)->first()) {
            return $existing; // the same submission twice records one return
        }
        try {
            return DB::transaction(function () use ($user, $line, $data, $key) {
                $lot = SourcingLot::whereKey($line->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                $line = SourcingLotLine::whereKey($line->id)->firstOrFail();
                $quantity = (string) ($data['quantity'] ?? '');
                $kind = (string) ($data['kind'] ?? '');
                if (! in_array($kind, ['accepted', 'unaccepted'], true)) {
                    throw new RuntimeException('State whether the goods were accepted into stock or rejected at arrival.');
                }
                if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $quantity) || bccomp($quantity, '0', 2) <= 0) {
                    throw new RuntimeException('Enter a quantity greater than zero.');
                }
                if ($line->supplier_id === null) {
                    throw new RuntimeException('This line has no supplier to return the goods to.');
                }
                if (mb_strlen(trim((string) ($data['return_reference'] ?? ''))) < 3) {
                    throw new RuntimeException('Enter the return document number (the supplier\'s or the carrier\'s).');
                }
                if (mb_strlen(trim((string) ($data['evidence_reference'] ?? ''))) < self::EVIDENCE_MIN || trim((string) ($data['reason'] ?? '')) === '') {
                    throw new RuntimeException('Give the reason for the return and the evidence that the goods were sent back.');
                }
                $on = (string) ($data['physical_returned_on'] ?? '');
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > today()->toDateString()) {
                    throw new RuntimeException('Give the date the goods physically left; it cannot be in the future.');
                }
                if (SupplierReturn::where('supplier_id', $line->supplier_id)->where('return_reference', trim($data['return_reference']))->whereNotIn('status', ['rejected', 'cancelled'])->exists()) {
                    throw new RuntimeException('That return document is already recorded for this supplier: one physical return is recorded once.');
                }
                $claimed = (int) ($data['claimed_amount'] ?? 0);
                if ($claimed < 0) {
                    throw new RuntimeException('The amount claimed cannot be negative.');
                }
                $method = $data['expected_method'] ?? null;
                if ($method !== null && ! in_array($method, ['payable', 'credit_note', 'refund', 'mixed'], true)) {
                    throw new RuntimeException('Choose how recovery is expected: reduce the payable, credit note, refund or a mix.');
                }

                if ($kind === 'accepted') {
                    self::assertSellbeesStock($lot);
                    $room = bcsub(Inventory::sliceBalances($line, null)['available'], self::committedUnits($line), 2);
                    $valued = InventoryValuation::lineFigures($line)['quantity'];
                    $room = bccomp($room, $valued, 2) < 0 ? $room : $valued;
                    $what = 'in Sellbees\' sellable stock, not reserved, not handed over to a client, not written off and not in another open return or case';
                } else {
                    if ($lot->ownership !== 'company' || $line->received_quantity === null) {
                        throw new RuntimeException('Only goods rejected at the arrival of a Sellbees-owned lot can be claimed back without ever having been inventory.');
                    }
                    $taken = (string) SupplierReturn::where('sourcing_lot_line_id', $line->id)->where('kind', 'unaccepted')->whereIn('status', [...SupplierReturn::ACTIVE, 'approved'])->sum('quantity');
                    $room = bcsub(bcsub((string) $line->received_quantity, (string) $line->usable_quantity, 2), $taken, 2);
                    $what = 'rejected at arrival and not already in another return';
                }
                if (bccomp($quantity, $room, 2) > 0) {
                    throw new RuntimeException('Only '.(bccomp($room, '0', 2) > 0 ? $room : '0.00').' units can go into this return: they must be '.$what.'.');
                }

                $return = SupplierReturn::create(['supplier_id' => $line->supplier_id, 'sourcing_lot_id' => $lot->id, 'sourcing_lot_line_id' => $line->id, 'kind' => $kind, 'quantity' => $quantity,
                    'reason' => trim($data['reason']), 'return_reference' => trim($data['return_reference']), 'physical_returned_on' => $on, 'evidence_reference' => trim($data['evidence_reference']),
                    'claimed_amount' => $claimed, 'expected_method' => $method, 'event_on' => $on, 'prepared_by' => $user->id, 'idempotency_key' => $key]);
                Audit::log('supplier_return.proposed', 'line '.$line->id, '#'.$return->id.' '.$kind.' '.$quantity.' ref '.$return->return_reference.', claim '.$claimed);

                return $return->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return SupplierReturn::where('idempotency_key', $key)->firstOrFail();
        }
    }

    public static function cancel(SupplierReturn $return, User $user, string $reason): void
    {
        DB::transaction(function () use ($return, $user, $reason) {
            $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if (! in_array($return->status, SupplierReturn::ACTIVE, true)) {
                throw new RuntimeException('Only a return that has not been decided can be cancelled.');
            }
            if ($return->prepared_by !== $user->id && ! $user->can('supplier.return.approve')) {
                throw new RuntimeException('Only the person who requested it, or an approver, can cancel this return.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('Give a reason for cancelling.');
            }
            $return->update(['status' => 'cancelled', 'decision_note' => trim($reason)]);
            SupplierRecoveryRegistry::release('supplier_return', $return->id);
            Audit::log('supplier_return.cancelled', 'return '.$return->id, trim($reason));
        });
    }

    // ── Supplier acceptance, Finance verification, Board approval ────────────────────────────────────────────────────────────────

    /** Finance records what the supplier said, with the document that proves it. A claim is not recognised by being made, only by being accepted. */
    public static function recordAcceptance(SupplierReturn $return, User $user, string $acceptance, ?int $acceptedAmount, ?string $document, ?string $note = null): void
    {
        DB::transaction(function () use ($return, $user, $acceptance, $acceptedAmount, $document, $note) {
            $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'requested') {
                throw new RuntimeException('The supplier\'s answer can only be recorded before Finance verifies the return.');
            }
            if (! $user->can('supplier.return.review')) {
                throw new RuntimeException('Only Finance can record the supplier\'s answer.');
            }
            if (! in_array($acceptance, ['accepted', 'partial', 'disputed', 'rejected'], true)) {
                throw new RuntimeException('Record the answer as accepted, partly accepted, disputed or rejected.');
            }
            $amount = in_array($acceptance, ['disputed', 'rejected'], true) ? 0 : ($acceptedAmount ?? ($acceptance === 'accepted' ? (int) $return->claimed_amount : null));
            if ($amount === null || $amount < 0 || $amount > (int) $return->claimed_amount || ($acceptance === 'partial' && ($amount <= 0 || $amount >= (int) $return->claimed_amount))) {
                throw new RuntimeException('The accepted amount must be within what was claimed ('.Money::format((int) $return->claimed_amount).'), and a partial acceptance must be between nothing and the whole claim.');
            }
            $document = trim((string) $document);
            if ($amount > 0 && mb_strlen($document) < 4) {
                throw new RuntimeException('An accepted recovery needs the supplier\'s credit note number or written acceptance.');
            }
            // One register for every workflow: the same credit note, acceptance or receipt of this supplier is recognised once, wherever it was first used.
            SupplierRecoveryRegistry::release('supplier_return', $return->id, ['acceptance', 'credit_note']);
            if ($document !== '') {
                SupplierRecoveryRegistry::claim((int) $return->supplier_id, $amount > 0 ? 'credit_note' : 'acceptance', $document, 'supplier_return', $return->id, $amount, $user->id);
            }
            $return->update(['acceptance' => $acceptance, 'accepted_amount' => $amount, 'supplier_document' => $document ?: null, 'acceptance_note' => $note, 'acceptance_by' => $user->id, 'acceptance_at' => now()]);
            Audit::log('supplier_return.acceptance', 'return '.$return->id, $acceptance.' '.$amount.' '.$document);
        });
    }

    /**
     * Finance checks the return and fixes how the accepted recovery is allocated. The allocation is checked against the value the goods really carry and against what
     * is really owed; whatever is not recovered is stated with its reason, never hidden inside the inventory cost.
     */
    public static function verify(SupplierReturn $return, User $user, string $note, int $payableReduction = 0, int $creditNote = 0, int $receivable = 0, ?string $differenceReason = null, ?string $differenceClass = null): void
    {
        DB::transaction(function () use ($return, $user, $note, $payableReduction, $creditNote, $receivable, $differenceReason, $differenceClass) {
            $lot = SourcingLot::whereKey($return->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'requested') {
                throw new RuntimeException('This return is not waiting for Finance verification.');
            }
            if (! $user->can('supplier.return.review')) {
                throw new RuntimeException('Only Finance can verify a supplier return.');
            }
            if ($return->prepared_by === $user->id) {
                throw new RuntimeException('You cannot verify a return you requested.');
            }
            if (trim($note) === '') {
                throw new RuntimeException('Record what Finance checked: the evidence, the supplier\'s answer and the treatment.');
            }
            if (! in_array($return->acceptance, ['accepted', 'partial'], true)) {
                throw new RuntimeException('The supplier has not accepted any recovery ('.$return->acceptance.'). Nothing can be recognised until it does; a disputed or unanswered claim is tracked, not booked.');
            }
            if ($payableReduction < 0 || $creditNote < 0 || $receivable < 0) {
                throw new RuntimeException('An allocation cannot be negative.');
            }
            $allocated = $payableReduction + $creditNote + $receivable;
            if ($return->kind === 'unaccepted') {
                if ($allocated !== 0) {
                    throw new RuntimeException('Rejected goods never entered inventory: nothing is allocated here. The supplier\'s refund or credit for them is handled on the supplier payment (advance refund or credit note).');
                }
                $return->update(['status' => 'verified', 'verified_by' => $user->id, 'verified_at' => now(), 'verify_note' => trim($note)]);
                Audit::log('supplier_return.verified', 'return '.$return->id, trim($note));

                return;
            }
            if ($allocated > (int) $return->accepted_amount) {
                throw new RuntimeException('The allocation ('.Money::format($allocated).') is more than the supplier accepted ('.Money::format((int) $return->accepted_amount).').');
            }
            $cost = self::carrying($lot, $return);
            $carrying = $cost['purchase'] + $cost['landed'];
            if ($allocated > $carrying) {
                throw new RuntimeException('The recovery ('.Money::format($allocated).') is above the carrying value of these goods ('.Money::format($carrying).'). There is no supported treatment for a gain on return: reduce the allocation or resolve it with Finance first.');
            }
            if ($carrying > $allocated && mb_strlen(trim((string) $differenceReason)) < self::EVIDENCE_MIN) {
                throw new RuntimeException('Carrying value '.Money::format($carrying).' is not fully recovered: state why '.Money::format($carrying - $allocated).' is not (non-refundable freight, restocking charge, disputed amount).');
            }
            self::assertDifferenceClass($carrying - $allocated, $differenceClass, $cost['landed']);
            if ($payableReduction > AdvanceSettlementService::payableOutstanding($lot, (int) $return->supplier_id, includePending: true)) {
                throw new RuntimeException('Only '.Money::format(AdvanceSettlementService::payableOutstanding($lot, (int) $return->supplier_id, includePending: true)).' is still owed to this supplier on this lot: a return cannot reduce a payable that is already paid or does not exist. Use a credit note or a receivable.');
            }
            $return->update(['payable_reduction' => $payableReduction, 'credit_note_amount' => $creditNote, 'receivable_amount' => $receivable, 'difference_reason' => $differenceReason ? trim($differenceReason) : null,
                'difference_class' => $carrying > $allocated ? $differenceClass : null, 'status' => 'verified', 'verified_by' => $user->id, 'verified_at' => now(), 'verify_note' => trim($note)]);
            Audit::log('supplier_return.verified', 'return '.$return->id, trim($note).' P '.$payableReduction.' K '.$creditNote.' R '.$receivable);
        });
    }

    /** A cost that is not recovered is classified, and freight can only be as much as the landed cost the goods carry (never a way to expense purchase cost unexplained). */
    private static function assertDifferenceClass(int $difference, ?string $class, int $landed): void
    {
        if ($difference <= 0) {
            return;
        }
        if (! array_key_exists((string) $class, self::DIFFERENCE_CLASSES)) {
            throw new RuntimeException('Classify the part of the cost that is not recovered ('.Money::format($difference).'): '.collect(self::DIFFERENCE_CLASSES)->pluck('label')->implode(', ').'.');
        }
        if ($class === 'nonrefundable_freight' && $difference > $landed) {
            throw new RuntimeException('Non-refundable freight or handling cannot exceed the landed cost these goods carry ('.Money::format($landed).'); classify the rest separately.');
        }
    }

    /**
     * The value these goods carry in the lot line's cost layers now, or a refusal.
     *
     * @return array{purchase: int, landed: int}
     */
    private static function carrying(SourcingLot $lot, SupplierReturn $return): array
    {
        self::assertSellbeesStock($lot);
        $line = $lot->lines()->findOrFail($return->sourcing_lot_line_id);
        InventoryValuation::assertIssuable($line);

        return InventoryValuation::issueCost($line, (string) $return->quantity);
    }

    public static function decide(SupplierReturn $return, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($return, $approve, $user, $note) {
            $lot = SourcingLot::whereKey(SupplierReturn::findOrFail($return->id)->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            if ($return->status !== 'verified') {
                throw new RuntimeException(in_array($return->status, ['approved', 'rejected'], true) ? 'Already decided.' : 'Finance must verify this return before the Board can decide it.');
            }
            if ($return->prepared_by === $user->id || $return->verified_by === $user->id) {
                throw new RuntimeException('You cannot approve a return you requested or verified.');
            }
            if (! $user->can('supplier.return.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide a supplier return.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $return->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);
                SupplierRecoveryRegistry::release('supplier_return', $return->id);
                Audit::log('supplier_return.rejected', 'return '.$return->id, trim($note));

                return;
            }
            if (JournalEntry::where('source_type', 'supplier_return')->where('source_id', $return->id)->exists()) {
                throw new RuntimeException('This return has already been posted.');
            }
            if ($return->kind === 'unaccepted') {
                $taken = bcsub((string) SupplierReturn::where('sourcing_lot_line_id', $return->sourcing_lot_line_id)->where('kind', 'unaccepted')->where('status', 'approved')->sum('quantity'), '0', 2);
                $line = SourcingLotLine::findOrFail($return->sourcing_lot_line_id);
                if (bccomp(bcadd($taken, (string) $return->quantity, 2), bcsub((string) $line->received_quantity, (string) $line->usable_quantity, 2), 2) > 0) {
                    throw new RuntimeException('More goods are now claimed as rejected than the arrival recorded as rejected.');
                }
                $return->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null, 'posted_on' => null, 'carrying_value' => 0, 'difference_value' => 0]);
                Audit::log('supplier_return.approved', 'return '.$return->id, 'unaccepted goods: claim recorded, nothing posted');

                return;
            }

            $line = $lot->lines()->findOrFail($return->sourcing_lot_line_id);
            self::assertSellbeesStock($lot);
            InventoryValuation::assertIssuable($line);
            $quantity = (string) $return->quantity;
            if (bccomp($quantity, Inventory::sliceBalances($line, null)['available'], 2) > 0) {
                throw new RuntimeException('Those units are no longer in Sellbees\' sellable stock (reserved, handed over, written off or already returned).');
            }
            $cost = InventoryValuation::issueCost($line, $quantity); // exact integer value leaving the layers
            $carrying = $cost['purchase'] + $cost['landed'];
            $allocated = (int) $return->payable_reduction + (int) $return->credit_note_amount + (int) $return->receivable_amount;
            if ($allocated > $carrying || $allocated > (int) $return->accepted_amount) {
                throw new RuntimeException('The recovery allocated ('.Money::format($allocated).') is above the carrying value now ('.Money::format($carrying).') or what the supplier accepted: Finance must verify it again.');
            }
            if ((int) $return->payable_reduction > AdvanceSettlementService::payableOutstanding($lot, (int) $return->supplier_id, includePending: true)) {
                throw new RuntimeException('The supplier payable this return was to reduce is no longer outstanding; Finance must verify the allocation again.');
            }
            $difference = $carrying - $allocated;
            if ($difference > 0 && mb_strlen(trim((string) $return->difference_reason)) < self::EVIDENCE_MIN) {
                throw new RuntimeException('The part of the cost that is not recovered has no stated reason, so the treatment cannot be established.');
            }
            self::assertDifferenceClass($difference, $return->difference_class, $cost['landed']);
            $event = $return->physical_returned_on->toDateString();
            $closed = AccountingService::isClosed($event);
            $date = $closed ? today()->toDateString() : $event;
            AccountingService::assertOpen($date, 'this supplier return');

            $lines = [['assets.inventory', null, 0, $carrying]];
            foreach ([['liabilities', (int) $return->payable_reduction], ['supplier.credit', (int) $return->credit_note_amount], ['assets.supplier_receivable', (int) $return->receivable_amount], ['supplier_return_difference', $difference]] as [$account, $amount]) {
                if ($amount > 0) {
                    $lines[] = [$account, null, $amount, 0];
                }
            }
            $entry = Ledger::post($date, 'Goods returned to supplier #'.$return->supplier_id.' (return #'.$return->id.', ref '.$return->return_reference.', lot '.$lot->number.'): '.$quantity.' '.$line->description
                .($closed ? ' - physical return dated '.$event.' in a closed period; recorded in the open period, flagged for review' : ''), 'supplier_return', $return->id, $lines, $user->id);
            $return->update(['posted_on' => $date]);
            InventoryValuation::recordSupplierReturn($return, $line, $cost['purchase'], $cost['landed'], $entry->id, $user);
            Inventory::move($line, 'adjust', ['available' => bcmul($quantity, '-1', 2)], 'sret:'.$return->id, null, $user, 'Returned to supplier (return #'.$return->id.', ref '.$return->return_reference.')');
            $return->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null, 'purchase_value' => $cost['purchase'], 'landed_value' => $cost['landed'],
                'carrying_value' => $carrying, 'difference_value' => $difference, 'prior_period' => $closed, 'journal_entry_id' => $entry->id]);
            Audit::log('supplier_return.approved', 'return '.$return->id, $quantity.' units, cost removed '.$carrying.', recovery '.$allocated.', not recovered '.$difference);
        });
    }

    // ── Later settlements: a credit applied to a payable, cash received against the receivable ──────────────────────────────────────

    public static function proposeSettlement(SupplierReturn $return, User $user, string $method, int $amount, string $reference, string $settledOn, ?int $accountId, string $key): SupplierReturnSettlement
    {
        if ($existing = SupplierReturnSettlement::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($return, $user, $method, $amount, $reference, $settledOn, $accountId, $key) {
                $lot = SourcingLot::whereKey(SupplierReturn::findOrFail($return->id)->sourcing_lot_id)->lockForUpdate()->firstOrFail();
                $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
                if (! $user->can('supplier.return.review')) {
                    throw new RuntimeException('Only Finance can record a credit application or a refund.');
                }
                if ($return->status !== 'approved' || $return->kind !== 'accepted') {
                    throw new RuntimeException('Only an approved return of goods from inventory has a recovery to settle.');
                }
                if (! in_array($method, ['credit_applied', 'cash_refund'], true)) {
                    throw new RuntimeException('Choose credit applied to a payable, or cash refund received.');
                }
                if ($amount <= 0) {
                    throw new RuntimeException('Amount must be greater than zero.');
                }
                if (mb_strlen(trim($reference)) < self::EVIDENCE_MIN) {
                    throw new RuntimeException($method === 'cash_refund' ? 'Enter the bank receipt reference of the refund.' : 'Enter the payable (invoice or payment reference) the credit is applied to.');
                }
                if ($settledOn > today()->toDateString()) {
                    throw new RuntimeException('The settlement cannot be dated in the future.');
                }
                self::assertRoom($lot, $return, $method, $amount, null);
                if ($method === 'cash_refund') {
                    if ($accountId === null && FinancialAccount::query()->exists()) {
                        throw new RuntimeException('Choose the financial account the refund was received into.');
                    }
                    AccountingService::assertActive($accountId);
                }
                $settlement = SupplierReturnSettlement::create(['supplier_return_id' => $return->id, 'method' => $method, 'amount' => $amount, 'reference' => trim($reference), 'financial_account_id' => $accountId,
                    'settled_on' => $settledOn, 'prepared_by' => $user->id, 'idempotency_key' => $key]);
                if ($method === 'cash_refund') {
                    // The same bank receipt cannot be collected twice, here or as a supplier payment refund.
                    SupplierRecoveryRegistry::claim((int) $return->supplier_id, 'bank_receipt', $reference, 'supplier_return_settlement', $settlement->id, $amount, $user->id);
                }
                Audit::log('supplier_return.settlement_proposed', 'return '.$return->id, $method.' '.$amount.' ref '.trim($reference));

                return $settlement->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return SupplierReturnSettlement::where('idempotency_key', $key)->firstOrFail();
        }
    }

    /** The settlement cannot exceed what is still open, counting posted and pending ones (so two pending requests cannot together collect more than was recognised). */
    private static function assertRoom(SourcingLot $lot, SupplierReturn $return, string $method, int $amount, ?int $exceptId): void
    {
        $other = fn (string $m, array $statuses) => (int) $return->settlements()->where('method', $m)->whereIn('status', $statuses)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('amount');
        if ($method === 'cash_refund') {
            $room = (int) $return->receivable_amount - $other('cash_refund', ['posted', 'pending']);
            if ($amount > $room) {
                throw new RuntimeException('Only '.Money::format(max(0, $room)).' of the supplier receivable is still to be collected: a refund is recognised once.');
            }

            return;
        }
        $room = (int) $return->credit_note_amount - $other('credit_applied', ['posted', 'pending']);
        if ($amount > $room) {
            throw new RuntimeException('Only '.Money::format(max(0, $room)).' of this credit note is still unapplied.');
        }
        $pendingOtherReturns = (int) SupplierReturnSettlement::where('method', 'credit_applied')->where('status', 'pending')->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->where('supplier_return_id', '!=', $return->id)
            ->whereIn('supplier_return_id', SupplierReturn::where('sourcing_lot_id', $lot->id)->where('supplier_id', $return->supplier_id)->select('id'))->sum('amount');
        $payable = AdvanceSettlementService::payableOutstanding($lot, (int) $return->supplier_id, includePending: true) - $pendingOtherReturns;
        if ($amount > $payable) {
            throw new RuntimeException('Only '.Money::format(max(0, $payable)).' is owed to this supplier on this lot: a credit can only be applied to a valid payable.');
        }
    }

    public static function decideSettlement(SupplierReturnSettlement $settlement, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($settlement, $approve, $user, $note) {
            $return = SupplierReturn::findOrFail(SupplierReturnSettlement::findOrFail($settlement->id)->supplier_return_id);
            $lot = SourcingLot::whereKey($return->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $settlement = SupplierReturnSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($settlement->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($settlement->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve a settlement you recorded.');
            }
            if (! $user->can('supplier.return.approve')) {
                throw new RuntimeException('Only a Board member authorised for inventory decisions can decide a supplier settlement.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $settlement->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);
                SupplierRecoveryRegistry::release('supplier_return_settlement', $settlement->id); // the receipt is free for the event that really happened

                return;
            }
            if (JournalEntry::where('source_type', 'supplier_return_settlement')->where('source_id', $settlement->id)->exists()) {
                throw new RuntimeException('This settlement has already been posted.');
            }
            self::assertRoom($lot, $return, $settlement->method, (int) $settlement->amount, $settlement->id);
            $closed = AccountingService::isClosed($settlement->settled_on->toDateString());
            $date = $closed ? today()->toDateString() : $settlement->settled_on->toDateString();
            AccountingService::assertOpen($date, 'this settlement');
            $amount = (int) $settlement->amount;
            $suffix = $closed ? ' (dated '.$settlement->settled_on->toDateString().' in a closed period; recorded in the open period)' : '';
            if ($settlement->method === 'cash_refund') {
                $accountId = AccountingService::activeAccountId($settlement->financial_account_id);
                $entry = Ledger::post($date, 'Supplier refund received against return #'.$return->id.' (ref '.$settlement->reference.')'.$suffix, 'supplier_return_settlement', $settlement->id, [
                    ['cash', null, $amount, 0, $accountId], ['assets.supplier_receivable', null, 0, $amount],
                ], $user->id);
            } else {
                $entry = Ledger::post($date, 'Supplier credit of return #'.$return->id.' applied to a payable (ref '.$settlement->reference.')'.$suffix, 'supplier_return_settlement', $settlement->id, [
                    ['liabilities', null, $amount, 0], ['supplier.credit', null, 0, $amount],
                ], $user->id);
            }
            $settlement->update(['status' => 'posted', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null, 'journal_entry_id' => $entry->id]);
            Audit::log('supplier_return.settlement_posted', 'return '.$return->id, $settlement->method.' '.$amount);
        });
    }

    // ── Claims for goods that never entered inventory, recovered through the supplier payment ───────────────────────────────────

    /**
     * Rejected goods never entered inventory, so the supplier's money back for them is the existing advance refund or credit-note correction of the supplier payment. This
     * links that correction to the claim, once, so the claim is shown recovered by exactly the money that came back and no more: a correction can satisfy only one claim, the
     * correction must belong to the same supplier and lot, and the linked corrections cannot exceed what the supplier accepted. Nothing is posted here.
     */
    public static function linkPaymentRecovery(SupplierReturn $return, Expense $correction, User $user): void
    {
        DB::transaction(function () use ($return, $correction, $user) {
            $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $correction = Expense::whereKey($correction->id)->firstOrFail();
            if (! $user->can('supplier.return.review')) {
                throw new RuntimeException('Only Finance can link a refund or credit note to a claim.');
            }
            if ($return->kind !== 'unaccepted' || $return->status !== 'approved') {
                throw new RuntimeException('Only an approved claim for goods rejected at arrival is recovered through the supplier payment; goods from inventory are settled on the return itself.');
            }
            $payment = $correction->reverses_id ? SupplierPayment::where('expense_id', $correction->reverses_id)->first() : null;
            if (! $payment || ! in_array($correction->correction_type, ['refund_received', 'credit_note'], true) || ! in_array($correction->status, ['pending', 'verified'], true)) {
                throw new RuntimeException('Choose a refund or credit-note correction of a supplier payment that is pending or verified.');
            }
            if ((int) $payment->supplier_id !== (int) $return->supplier_id || (int) $payment->sourcing_lot_id !== (int) $return->sourcing_lot_id) {
                throw new RuntimeException('That correction belongs to another supplier or lot.');
            }
            if (SupplierRecoveryDocument::where('document_key', SupplierRecoveryRegistry::key('expense-'.$correction->id))->where('source_type', 'supplier_return')->where('source_id', $return->id)->where('active', 1)->exists()) {
                return; // already linked: the same request twice is one link
            }
            $linked = SupplierRecoveryRegistry::total('supplier_return', $return->id, 'payment_correction');
            if ($linked + (int) $correction->amount > (int) $return->accepted_amount) {
                throw new RuntimeException('The corrections linked ('.Money::format($linked + (int) $correction->amount).') would exceed what the supplier accepted for these goods ('.Money::format((int) $return->accepted_amount).').');
            }
            SupplierRecoveryRegistry::claim((int) $return->supplier_id, 'payment_correction', 'expense-'.$correction->id, 'supplier_return', $return->id, (int) $correction->amount, $user->id);
            Audit::log('supplier_return.recovery_linked', 'return '.$return->id, 'correction expense '.$correction->id.' '.$correction->amount);
        });
    }
}
