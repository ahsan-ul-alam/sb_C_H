<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\CostRecognition;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LoanReceivable;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\SourcingLot;
use App\Models\SourcingLotCost;
use App\Models\SupplierPayment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Is the accounting COMPLETE, not just arithmetically balanced? A ledger whose entries balance says nothing about whether every
 * receipt has its sourcing record, whether costs are classified correctly or whether cash was ever checked against a statement.
 * The answer is kept in five layers that are never merged into one comfortable "OK":
 *
 *   1  Mathematical ledger integrity     (IntegrityGate: balanced, no doubled entry)
 *   2  Source transaction completeness   (verified records that never reached the ledger, receipts with no invoice)
 *   3  Project sourcing completeness     (product receipts with no sourcing record, finished lots with no arrival)
 *   4  Classification accuracy           (unclassified receipts, a loan-looking expense, expenses with no document)
 *   5  Verified account reconciliation   (accounts with no verified statement, unexplained differences)
 *
 * Severity: blocker (stops finalisation, see below), warning (needs an owner decision or evidence), nothing is silently dropped.
 * What blocks, and only that: closing or verifying the MONTH of an unresolved receipt, preparing that month's profit allocation,
 * and settling a lot with no arrival record. Draft lots, purchases in progress and ordinary partial payments never block anything.
 * Read-only: nothing here posts, links or classifies anything by itself.
 */
class FinancialCompleteness
{
    /** First month (YYYY-MM) from which unclassified receipts block their month; null = report only. */
    public static function enforcedFrom(): ?string
    {
        $value = config('finance.completeness_from');

        return $value ? CarbonImmutable::parse((string) $value)->format('Y-m') : null;
    }

    /**
     * Every customer receipt in the ledger currency with what it is for and whether a sourcing record backs it. A receipt whose
     * invoice was billed from a sourcing lot is product sourcing by construction and cannot be reclassified away; any other
     * receipt is whatever the owner classified it as, or unclassified.
     *
     * @return Collection<int, array{payment: Payment, nature: ?string, source: ?string, covered: bool, month: string}>
     */
    public static function receipts(): Collection
    {
        $classified = ReceiptClassification::pluck('nature', 'payment_id');
        $billedInvoices = DB::table('sourcing_allocations')->whereNotNull('billed_invoice_id')->pluck('billed_invoice_id')->flip();

        return Payment::with('invoice')->whereHas('project', fn ($q) => $q->where('currency', config('finance.currency')))
            ->orderBy('paid_on')->orderBy('id')->get()->map(function (Payment $p) use ($classified, $billedInvoices) {
                $billed = $p->invoice_id !== null && isset($billedInvoices[$p->invoice_id]);
                $nature = $billed ? 'product_sourcing' : ($classified[$p->id] ?? null);

                return ['payment' => $p, 'nature' => $nature, 'source' => $billed ? 'sourcing billing' : ($nature ? 'classified by Finance' : null),
                    'covered' => $nature !== 'product_sourcing' || $billed, 'month' => $p->paid_on->format('Y-m')];
            });
    }

    /**
     * Problems that stop this month being closed or verified, or its profit being allocated.
     *
     * @return list<string>
     */
    public static function blockersForMonth(string $month): array
    {
        return collect([...self::receiptFindings(), ...self::advanceFindings()])->where('month', $month)->where('severity', 'blocker')->pluck('message')->values()->all();
    }

    /** @throws \RuntimeException when anything unresolved materially affects the month */
    public static function assertMonthComplete(string $month, string $action): void
    {
        $blockers = self::blockersForMonth($month);
        if ($blockers !== []) {
            throw new \RuntimeException('Cannot '.$action.' '.$month.' while its financial records are incomplete: '.implode(' ', $blockers));
        }
    }

    /**
     * A lot is financially finalised (settled) only once the goods' arrival at Sellbees's room is on record for every line, because
     * that is where the cost boundary of the business lies. Draft and in-progress lots are never held back.
     *
     * @return list<string>
     */
    public static function lotBlockers(SourcingLot $lot): array
    {
        $blockers = [];
        $missing = $lot->lines()->whereNull('received_quantity')->pluck('description');
        if ($missing->isNotEmpty()) {
            $blockers[] = 'Record the arrival (quantity received and date) of: '.$missing->implode(', ').'.';
        }
        if (($prepaid = CostRecognitionService::unconfirmedPrepayments($lot)) > 0) {
            $blockers[] = 'Transport of '.Money::format($prepaid).' was paid in advance: confirm the service was done.';
        }
        // A lot paid by supplier advance is financially complete only when every advance has been settled against accepted goods
        // (or refunded or credited) and the goods' acceptance is documented. Until then the advance is simply an asset of a live lot.
        if ($lot->payments()->whereHas('expense', fn ($q) => $q->where('classification', 'supplier_advance'))->exists()) {
            if (($held = AdvanceSettlementService::unsettled($lot)) > 0) {
                $blockers[] = 'A supplier advance of '.Money::format($held).' is still held: settle it against accepted goods, or have it refunded or credited.';
            }
            if ($lot->advanceSettlements()->where('status', 'pending')->exists()) {
                $blockers[] = 'An advance settlement is awaiting verification.';
            }
            $undocumented = $lot->lines()->whereNotNull('received_quantity')->whereRaw("COALESCE(acceptance_reference, '') = ''")->pluck('description');
            if ($undocumented->isNotEmpty()) {
                $blockers[] = 'Enter the supplier delivery or inspection reference for: '.$undocumented->implode(', ').'.';
            }
        }

        return $blockers;
    }

    /**
     * @return array{layers: array<string, array{title: string, status: string, findings: list<array<string, mixed>>}>, ledger_balanced: bool, fully_reconciled: bool, headline: string}
     */
    public static function report(): array
    {
        $receiptFindings = collect(self::receiptFindings());
        $advanceFindings = collect(self::advanceFindings());
        $layers = [
            'ledger' => self::layer('1. Mathematical ledger integrity', array_map(fn ($m) => self::finding('blocker', $m), IntegrityGate::ledgerBlockers())),
            'source' => self::layer('2. Source transaction completeness', [
                ...array_map(fn ($m) => self::finding('blocker', $m), IntegrityGate::sourceBlockers()),
                ...self::sourceFindings(), ...$advanceFindings->where('layer', 'source')->all(),
            ]),
            'sourcing' => self::layer('3. Project sourcing completeness', [...$receiptFindings->where('layer', 'sourcing')->all(), ...$advanceFindings->where('layer', 'sourcing')->all(), ...self::lotFindings()]),
            'classification' => self::layer('4. Classification accuracy', [...$receiptFindings->where('layer', 'classification')->all(), ...self::classificationFindings()]),
            'accounts' => self::layer('5. Verified account reconciliation', [
                ...array_map(fn ($m) => self::finding('blocker', $m), IntegrityGate::statementBlockers()),
                ...self::accountFindings(),
            ]),
        ];
        $reconciled = collect($layers)->every(fn ($l) => $l['status'] === 'clear');

        return [
            'layers' => $layers, 'ledger_balanced' => $layers['ledger']['status'] === 'clear', 'fully_reconciled' => $reconciled,
            'headline' => $reconciled ? 'Every layer is clear.'
                : ($layers['ledger']['status'] === 'clear' ? 'The ledger balances arithmetically, but the accounting is NOT fully reconciled.' : 'The ledger itself is out of balance.'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array{title: string, status: string, findings: list<array<string, mixed>>}
     */
    private static function layer(string $title, array $findings): array
    {
        $severities = array_column($findings, 'severity');

        return ['title' => $title, 'findings' => array_values($findings),
            'status' => in_array('blocker', $severities, true) ? 'blocking' : (in_array('warning', $severities, true) ? 'needs review' : 'clear')];
    }

    /** @return array<string, mixed> */
    private static function finding(string $severity, string $message, ?int $amount = null, ?string $evidence = null, ?string $month = null, ?string $layer = null, ?string $link = null, ?string $linkLabel = null): array
    {
        // Every finding leads somewhere: the screen where it can be completed (the reconciliation is the fallback for pure ledger checks).
        return ['severity' => $severity, 'message' => $message, 'amount' => $amount, 'evidence' => $evidence, 'month' => $month, 'layer' => $layer,
            'link' => $link ?? route('finance.reconciliation'), 'link_label' => $linkLabel ?? 'Review'];
    }

    /**
     * Receipt-based findings (layers 3 and 4), grouped by the month of the receipt, which is the period they materially affect.
     *
     * @return list<array<string, mixed>>
     */
    private static function receiptFindings(): array
    {
        $findings = [];
        $enforced = self::enforcedFrom();
        foreach (self::receipts()->groupBy('month') as $month => $rows) {
            $label = fn (Collection $c) => $c->map(fn ($r) => ($r['payment']->invoice?->number ?? 'payment #'.$r['payment']->id).' ('.Money::format($r['payment']->amount).')')->implode(', ');

            $uncovered = $rows->filter(fn ($r) => ! $r['covered']);
            if ($uncovered->isNotEmpty()) {
                $findings[] = self::finding('blocker', $uncovered->count().' product-sourcing receipt(s) in '.$month.' have no sourcing record: '.$label($uncovered).'.', (int) $uncovered->sum(fn ($r) => $r['payment']->amount),
                    'Supplier bills and payments, transport and loading receipts, the arrival date and the lot they belong to; then bill them from a lot or record the lot allocation.', $month, 'sourcing',
                    route('sourcing.lots.create'), 'Create the sourcing lot');
            }
            $unclassified = $rows->filter(fn ($r) => $r['nature'] === null);
            if ($unclassified->isNotEmpty()) {
                $blocks = $enforced !== null && $month >= $enforced;
                $findings[] = self::finding($blocks ? 'blocker' : 'warning', $unclassified->count().' receipt(s) in '.$month.' are not classified (service, product sourcing or other): '.$label($unclassified).'.', (int) $unclassified->sum(fn ($r) => $r['payment']->amount),
                    'The owner states what each receipt is for. A product-sourcing receipt then needs its supplier cost and lot.', $month, 'classification',
                    route('finance.completeness').'#receipts', 'Classify the receipts');
            }
        }

        return $findings;
    }

    /**
     * Supplier-advance and acquisition-cost checks. Quiet on a cash-basis installation (no advance, settlement or release exists); on
     * an accrual one they separate a legitimate live advance (never reported) from a discrepancy that could make a result wrong.
     *
     * @return list<array<string, mixed>>
     */
    private static function advanceFindings(): array
    {
        $f = [];
        $month = fn ($date) => CarbonImmutable::parse($date)->format('Y-m');
        $advances = SupplierPayment::with(['expense', 'corrections', 'lot'])->whereHas('expense', fn ($q) => $q->where('classification', 'supplier_advance'))->get();

        foreach ($advances->whereNull('sourcing_lot_id') as $p) {
            $f[] = self::finding('blocker', 'Supplier advance #'.$p->id.' ('.Money::format($p->amount).') is not attached to a sourcing lot.', (int) $p->amount, 'The lot and purchase order it paid for.', $month($p->paid_on), 'sourcing');
        }
        // The same payment recorded twice (a legitimate repeat of an amount is possible, so this asks for evidence rather than blocking).
        $duplicates = SupplierPayment::whereNull('voided_at')->get()->groupBy(fn ($p) => implode('|', [$p->supplier_id, $p->sourcing_lot_id, $p->amount, $p->paid_on->toDateString(), $p->reference ?? '']))->filter(fn ($g) => $g->count() > 1);
        foreach ($duplicates as $group) {
            $p = $group->first();
            $f[] = self::finding('warning', 'Possible duplicate supplier payment: '.$group->count().' payments of '.Money::format($p->amount).' on '.$p->paid_on->format('j M Y').' for the same supplier, lot and reference.', (int) $p->amount * ($group->count() - 1), 'The bank record showing whether the money really left twice.', $month($p->paid_on), 'sourcing');
        }
        foreach ($advances->whereNotNull('sourcing_lot_id')->groupBy('sourcing_lot_id') as $lotId => $payments) {
            $lot = $payments->first()->lot;
            foreach ($payments->groupBy('supplier_id') as $supplierId => $group) {
                $authorised = (int) $lot->lines()->where('supplier_id', $supplierId)->get()->sum(fn ($l) => $l->purchaseCost());
                $paid = (int) $group->sum(fn ($p) => $p->effectiveAmount());
                if ($paid > $authorised) {
                    $f[] = self::finding('blocker', 'Supplier advances on lot '.$lot->number.' ('.Money::format($paid).') exceed the authorised purchase amount ('.Money::format($authorised).').', $paid - $authorised, 'The purchase order that authorises the larger amount, or a refund of the excess.', $month($group->max('paid_on')), 'sourcing');
                }
                $undocumented = $lot->lines()->where('supplier_id', $supplierId)->whereNotNull('received_quantity')->whereRaw("COALESCE(acceptance_reference, '') = ''")->pluck('description');
                if ($undocumented->isNotEmpty()) {
                    $f[] = self::finding('warning', 'Goods arrived on lot '.$lot->number.' without acceptance evidence: '.$undocumented->implode(', ').'.', null, 'The supplier delivery note or inspection record for each line.', $month($group->max('paid_on')), 'sourcing');
                }
            }
            if (in_array($lot->status, ['completed', 'settled'], true) && ($held = AdvanceSettlementService::unsettled($lot)) > 0) {
                $f[] = self::finding('blocker', 'Lot '.$lot->number.' is '.strtolower($lot->statusLabel()).' but still holds an unsettled supplier advance of '.Money::format($held).'.', $held, 'Settle it against accepted goods, or record the supplier refund or credit note.', $month($payments->max('paid_on')), 'sourcing');
            }
            $mixed = $lot->payments()->whereNull('voided_at')->whereHas('expense', fn ($q) => $q->where('status', 'verified')->whereNotIn('classification', ['supplier_advance']))->count();
            if ($mixed > 0) {
                $f[] = self::finding('warning', 'Lot '.$lot->number.' mixes cash-basis supplier payments (already expensed) with supplier advances; its earlier payments stay expensed and are not capitalised.', null, 'Confirm the earlier payments were meant to be expensed.', null, 'sourcing');
            }
        }

        foreach (AdvanceSettlement::where('status', 'verified')->with('lot')->get() as $s) {
            if (AdvanceSettlementService::acceptedValue($s->lot, $s->supplier_id) <= 0) {
                $f[] = self::finding('blocker', 'An advance of '.Money::format($s->amount).' on lot '.$s->lot->number.' was settled with no accepted goods on record.', (int) $s->amount, 'The accepted quantity and supplier documentation, or reverse the settlement.', $month($s->settled_on), 'sourcing');
            }
            if (! JournalEntry::where('source_type', 'advance_settlement')->where('source_id', $s->id)->exists()) {
                $f[] = self::finding('blocker', 'Verified advance settlement #'.$s->id.' has no journal entry.', (int) $s->amount, null, $month($s->settled_on), 'source');
            }
        }
        foreach (CostRecognition::where('status', 'verified')->with('lot')->get() as $r) {
            if (! JournalEntry::where('source_type', 'cost_recognition')->where('source_id', $r->id)->exists()) {
                $f[] = self::finding('blocker', 'Verified cost recognition #'.$r->id.' has no journal entry.', (int) $r->amount, null, $month($r->recognised_on), 'source');
            }
        }
        foreach (SourcingLot::whereHas('costRecognitions', fn ($q) => $q->where('status', 'verified'))->get() as $lot) {
            $released = (int) $lot->costRecognitions()->where('status', 'verified')->sum('amount');
            if ($released > CostRecognitionService::held($lot)) {
                $f[] = self::finding('blocker', 'Lot '.$lot->number.' has more product cost recognised ('.Money::format($released).') than it ever capitalised ('.Money::format(CostRecognitionService::held($lot)).'): cost recognised twice.', $released - CostRecognitionService::held($lot), 'Reverse the extra recognition.', $month($lot->costRecognitions()->where('status', 'verified')->max('recognised_on')), 'sourcing');
            }
        }
        // The same transport/handling cost entered twice on one lot, both paid.
        foreach (SourcingLotCost::whereNotNull('expense_id')->where('kind', 'actual')->get()->groupBy(fn ($c) => implode('|', [$c->sourcing_lot_id, $c->category, $c->amount, $c->incurred_on->toDateString(), $c->payee ?? '']))->filter(fn ($g) => $g->count() > 1) as $group) {
            $c = $group->first();
            $f[] = self::finding('warning', 'Possible double-counted acquisition cost: '.$group->count().' paid '.$c->category.' costs of '.Money::format($c->amount).' on the same day for lot #'.$c->sourcing_lot_id.'.', (int) $c->amount * ($group->count() - 1), 'The receipts showing they are two separate services.', $month($c->incurred_on), 'sourcing');
        }
        // Advances and capitalised costs should leave an identifiable account, not unassigned cash.
        // (The ledger line is what counts: a payment that named no account is posted to the default account, which is identifiable.)
        $unassigned = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('e.source_type', 'expense')
            ->whereIn('e.source_id', Expense::whereIn('classification', SourcingBasis::ASSET_CLASSIFICATIONS)->where('status', 'verified')->whereNull('reverses_id')->select('id'))
            ->where('l.account', 'cash')->whereNull('l.financial_account_id');
        if ((clone $unassigned)->exists()) {
            $f[] = self::finding('warning', (clone $unassigned)->count().' supplier advance / acquisition payment(s) left money from no identifiable financial account (unassigned cash).', (int) (clone $unassigned)->sum('l.credit'), 'Which cash, bank or mobile-banking account each was paid from.', null, 'sourcing');
        }

        return $f;
    }

    /**
     * What the owner must supply for every historical product receipt and every lot that no project is tied to. Nothing is linked or
     * assumed from quantities or amounts; this only states the evidence required.
     *
     * @return list<array{subject: string, known: string, needed: list<string>}>
     */
    public static function evidenceChecklist(): array
    {
        $needed = ['Supplier identity', 'Supplier invoice or purchase order', 'Supplier payment amount', 'Payment date', 'Payment method', 'Payment reference', 'Arrival date',
            'Accepted quantity (and any shortage or damage)', 'Transportation amount', 'Which project and invoice it belongs to'];
        $rows = [];
        foreach (self::receipts()->filter(fn ($r) => $r['nature'] === 'product_sourcing' && ! $r['covered']) as $r) {
            $p = $r['payment'];
            $rows[] = ['subject' => 'Receipt '.($p->invoice?->number ?? 'payment #'.$p->id).' ('.Money::format($p->amount).', '.$p->paid_on->format('j M Y').', '.$p->project?->name.')',
                'known' => 'Client received; no supplier cost, payment, arrival or lot is recorded for it.', 'needed' => $needed];
        }
        foreach (SourcingLot::where('status', '!=', 'cancelled')->whereDoesntHave('allocations')->get() as $lot) {
            $rows[] = ['subject' => 'Lot '.$lot->number.' "'.$lot->title.'" ('.$lot->statusLabel().')',
                'known' => 'Recorded: '.$lot->lines()->get()->map(fn ($l) => $l->description.' x'.$l->quantity.' at '.Money::format($l->unit_price))->implode('; ')
                    .'. Not tied to any project or invoice; no supplier payment'.($lot->payments()->exists() ? '' : ' recorded').'; arrival '.($lot->lines()->whereNull('received_quantity')->exists() ? 'not recorded' : 'recorded').'.',
                'needed' => $needed];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function sourceFindings(): array
    {
        $findings = [];
        $unposted = LedgerPostingService::unpostedPayments();
        if ($unposted->isNotEmpty()) {
            $findings[] = self::finding('warning', $unposted->count().' customer payment(s) in the real-time window have no journal entry.', (int) $unposted->sum('amount'), 'Run finance:backfill-realtime (dry run first).');
        }
        $unlinked = self::receipts()->filter(fn ($r) => $r['payment']->invoice_id === null);
        if ($unlinked->isNotEmpty()) {
            $findings[] = self::finding('warning', $unlinked->count().' receipt(s) are not linked to an invoice: '.$unlinked->map(fn ($r) => 'payment #'.$r['payment']->id.' ('.Money::format($r['payment']->amount).')')->implode(', ').'.',
                (int) $unlinked->sum(fn ($r) => $r['payment']->amount), 'Link each to the invoice it settles, or state why none exists.', null, null, route('finance.completeness').'#receipts', 'See the receipts');
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private static function lotFindings(): array
    {
        $findings = [];
        foreach (SourcingLot::whereIn('status', ['completed', 'settled'])->get() as $lot) {
            if (($blockers = self::lotBlockers($lot)) !== []) {
                $findings[] = self::finding('warning', 'Lot '.$lot->number.' is '.strtolower($lot->statusLabel()).' with no arrival on record. '.$blockers[0], null, 'Arrival date and received quantity for each line.', null, null,
                    route('sourcing.lots.show', [$lot, 'tab' => 'receiving']), 'Record the arrival');
            }
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private static function classificationFindings(): array
    {
        $findings = [];
        $linked = LoanReceivable::whereIn('status', ['pending', 'verified'])->pluck('expense_id');
        $loanLike = Expense::where('status', 'verified')->whereNull('reverses_id')->where('is_asset', false)->whereIn('classification', ['operating', 'other'])
            ->where('category', '!=', 'Loan interest')->whereNotIn('id', $linked)
            ->where(fn ($q) => $q->where('category', 'like', '%loan%')->orWhere('description', 'like', '%loan%'))->get();
        foreach ($loanLike as $e) {
            $findings[] = self::finding('warning', 'Expense #'.$e->id.' ("'.$e->category.': '.$e->description.'", '.Money::format($e->amount).') looks like a loan but is booked as an '.$e->classification.' expense.', (int) $e->amount,
                'The Board states whether it is a recoverable loan (then reclassify it) or a genuine cost.', $e->spent_on->format('Y-m'), null, route('finance.loans-receivable'), 'Reclassify as a loan');
        }
        $undocumented = Expense::where('status', 'verified')->whereNull('reverses_id')->whereNull('document_id');
        if ($undocumented->count() > 0) {
            $largest = (clone $undocumented)->orderByDesc('amount')->limit(3)->get()->map(fn ($e) => '#'.$e->id.' '.Money::format($e->amount))->implode(', ');
            $findings[] = self::finding('warning', $undocumented->count().' verified expense(s) have no supporting document attached (largest: '.$largest.'); their classification as expense or asset rests on no evidence.', (int) $undocumented->sum('amount'), 'Invoice, receipt or voucher for each, so each can be classified (expense, asset, prepaid) on evidence.', null, null, route('finance.expenses'), 'Open the expenses');
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private static function accountFindings(): array
    {
        $findings = [];
        $legacy = AccountingService::balance(null);
        if ($legacy !== 0) {
            $findings[] = self::finding('warning', 'Unassigned (legacy) cash of '.Money::format($legacy).' is not in any financial account, so no statement can verify it.', $legacy,
                'Move it into the right accounts with the opening cash allocation, using verified statement balances.', null, null, route('finance.cash-allocation'), 'Allocate opening cash');
        }
        $verified = StatementReconciliationService::latestVerified()->pluck('financial_account_id')->all();
        foreach (FinancialAccount::where('is_active', true)->get() as $account) {
            if (! in_array($account->id, $verified, true)) {
                $findings[] = self::finding('warning', $account->name.' has no verified statement: its balance of '.Money::format(AccountingService::balance($account->id)).' is calculated, not verified.', AccountingService::balance($account->id),
                    'The balance on a real statement or cash count, its date and a reference, then a second person verifies it.', null, null, route('finance.statements'), 'Record the statement');
            }
        }

        return $findings;
    }
}
