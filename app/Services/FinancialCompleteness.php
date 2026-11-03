<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\LoanReceivable;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\SourcingLot;
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
        return collect(self::receiptFindings())->where('month', $month)->where('severity', 'blocker')->pluck('message')->values()->all();
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
        $missing = $lot->lines()->whereNull('received_quantity')->pluck('description');

        return $missing->isEmpty() ? [] : ['Record the arrival (quantity received and date) of: '.$missing->implode(', ').'.'];
    }

    /**
     * @return array{layers: array<string, array{title: string, status: string, findings: list<array<string, mixed>>}>, ledger_balanced: bool, fully_reconciled: bool, headline: string}
     */
    public static function report(): array
    {
        $receiptFindings = collect(self::receiptFindings());
        $layers = [
            'ledger' => self::layer('1. Mathematical ledger integrity', array_map(fn ($m) => self::finding('blocker', $m), IntegrityGate::ledgerBlockers())),
            'source' => self::layer('2. Source transaction completeness', [
                ...array_map(fn ($m) => self::finding('blocker', $m), IntegrityGate::sourceBlockers()),
                ...self::sourceFindings(),
            ]),
            'sourcing' => self::layer('3. Project sourcing completeness', [...$receiptFindings->where('layer', 'sourcing')->all(), ...self::lotFindings()]),
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
    private static function finding(string $severity, string $message, ?int $amount = null, ?string $evidence = null, ?string $month = null, ?string $layer = null): array
    {
        return ['severity' => $severity, 'message' => $message, 'amount' => $amount, 'evidence' => $evidence, 'month' => $month, 'layer' => $layer];
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
                    'Supplier bills and payments, transport and loading receipts, the arrival date and the lot they belong to; then bill them from a lot or record the lot allocation.', $month, 'sourcing');
            }
            $unclassified = $rows->filter(fn ($r) => $r['nature'] === null);
            if ($unclassified->isNotEmpty()) {
                $blocks = $enforced !== null && $month >= $enforced;
                $findings[] = self::finding($blocks ? 'blocker' : 'warning', $unclassified->count().' receipt(s) in '.$month.' are not classified (service, product sourcing or other): '.$label($unclassified).'.', (int) $unclassified->sum(fn ($r) => $r['payment']->amount),
                    'The owner states what each receipt is for. A product-sourcing receipt then needs its supplier cost and lot.', $month, 'classification');
            }
        }

        return $findings;
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
                (int) $unlinked->sum(fn ($r) => $r['payment']->amount), 'Link each to the invoice it settles, or state why none exists.');
        }

        return $findings;
    }

    /** @return list<array<string, mixed>> */
    private static function lotFindings(): array
    {
        $findings = [];
        foreach (SourcingLot::whereIn('status', ['completed', 'settled'])->get() as $lot) {
            if (($blockers = self::lotBlockers($lot)) !== []) {
                $findings[] = self::finding('warning', 'Lot '.$lot->number.' is '.strtolower($lot->statusLabel()).' with no arrival on record. '.$blockers[0], null, 'Arrival date and received quantity for each line.');
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
                'The Board states whether it is a recoverable loan (then reclassify it) or a genuine cost.', $e->spent_on->format('Y-m'));
        }
        $undocumented = Expense::where('status', 'verified')->whereNull('reverses_id')->whereNull('document_id');
        if ($undocumented->count() > 0) {
            $findings[] = self::finding('warning', $undocumented->count().' verified expense(s) have no supporting document attached.', (int) $undocumented->sum('amount'), 'Invoice, receipt or voucher for each, so each can be classified (expense, asset, prepaid) on evidence.');
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
                'Move it into the right accounts with the opening cash allocation, using verified statement balances.');
        }
        $verified = StatementReconciliationService::latestVerified()->pluck('financial_account_id')->all();
        foreach (FinancialAccount::where('is_active', true)->get() as $account) {
            if (! in_array($account->id, $verified, true)) {
                $findings[] = self::finding('warning', $account->name.' has no verified statement: its balance of '.Money::format(AccountingService::balance($account->id)).' is calculated, not verified.', AccountingService::balance($account->id),
                    'The balance on a real statement or cash count, its date and a reference, then a second person verifies it.');
            }
        }

        return $findings;
    }
}
