<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AccountStatement;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\ReceiptReview;
use App\Models\SourcingLot;
use Illuminate\Support\Collection;

/**
 * The read model behind the historical reconciliation screen. It only READS: every status is derived from the real records, none is stored here, so a screen can never
 * say something the books do not. The statuses are kept apart on purpose (received is not allocated to an account, which is not verified, which is not classified,
 * which is not posted), and the projection of cash from receipts that are not yet in the ledger is labelled as unverified.
 */
class HistoricalReconciliation
{
    /** @return list<array{label: string, type: string, account: ?FinancialAccount, state: string, note: string}> */
    public static function accountRequirements(): array
    {
        $cash = Payment::where('method', 'cash');
        $rows = [['bKash', 'mobile', 'bKash'], ['Bank Account', 'bank', 'Bank Account']];
        $out = [];
        foreach ($rows as [$label, $type, $name]) {
            $account = FinancialAccount::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            $out[] = ['label' => $label, 'type' => $type, 'name' => $name, 'account' => $account, 'state' => $account?->activation_status ?? 'not_prepared',
                'note' => 'Needs the real account identity, an opening date and a verified statement before it is activated.'];
        }
        $cashAccount = FinancialAccount::whereRaw('LOWER(name) = ?', ['cash in hand'])->first();
        $out[] = ['label' => 'Cash in Hand', 'type' => 'cash', 'name' => 'Cash in Hand', 'account' => $cashAccount, 'state' => $cashAccount?->activation_status ?? 'not_prepared',
            'note' => (clone $cash)->exists() ? (clone $cash)->count().' receipt(s) totalling '.number_format((clone $cash)->sum('amount') / 100, 2).' were recorded as cash: the operator must confirm whether physical cash was really held and where.' : 'No receipt was recorded as cash.'];

        return $out;
    }

    /** @return Collection<int, array<string, mixed>> one row per customer receipt, with each status kept separate */
    public static function receipts(): Collection
    {
        $completeness = FinancialCompleteness::receipts()->keyBy(fn ($r) => $r['payment']->id);
        $classified = ReceiptClassification::pluck('nature', 'payment_id');
        $reviews = ReceiptReview::get()->keyBy('payment_id');
        $accounts = FinancialAccount::pluck('name', 'id');
        $verifiedAccounts = AccountStatement::where('status', 'verified')->get()->groupBy('financial_account_id');
        $postedPayments = JournalEntry::where('source_type', 'payment')->pluck('source_id')->flip();

        return Payment::with(['invoice.items', 'project.client'])->orderBy('paid_on')->orderBy('id')->get()->map(function (Payment $p) use ($completeness, $classified, $reviews, $accounts, $verifiedAccounts, $postedPayments) {
            $review = $reviews->get($p->id);
            $nature = $classified[$p->id] ?? null;
            $c = $completeness->get($p->id);
            [$suggested, $basis] = ReceiptReviews::suggest($p);
            $accountVerified = $p->financial_account_id && ($verifiedAccounts->get($p->financial_account_id, collect())->contains(fn ($s) => $s->statement_date >= $p->paid_on));
            $month = $p->paid_on->format('Y-m');
            $posted = isset($postedPayments[$p->id]) ? 'real-time' : (AccountingPeriod::where('month', $month)->exists() && JournalEntry::where('source_type', 'period')->whereIn('source_id', AccountingPeriod::where('month', $month)->pluck('id'))->exists() ? 'at close' : 'no');
            $effective = $nature ?? $review?->proposed_nature;

            return [
                'payment' => $p, 'review' => $review, 'suggested' => $suggested, 'suggestion_basis' => $basis,
                'owner_confirmed' => $review !== null, 'account' => $p->financial_account_id ? ($accounts[$p->financial_account_id] ?? '#'.$p->financial_account_id) : null,
                'account_verified' => (bool) $accountVerified,
                'classification' => $nature ? 'applied' : ($review?->status ?? 'none'), 'nature' => $effective,
                'posted' => $posted,
                'awaiting_supplier_cost' => $effective === 'product_sourcing' && ($c['source'] ?? null) !== 'sourcing billing', // proposed, approved or applied: it still needs its sourcing record and supplier cost
                'received_unallocated' => $review !== null && $p->financial_account_id === null,
            ];
        });
    }

    /** @return Collection<int, array<string, mixed>> every historical cash journal line with what is and is not evidenced about it */
    public static function cashLines(): Collection
    {
        $expenses = Expense::get()->keyBy('id');

        return JournalLine::where('account', 'cash')->with('entry')->orderBy('journal_entry_id')->get()->map(function (JournalLine $l) use ($expenses) {
            $e = $l->entry;
            $x = $e->source_type === 'expense' ? $expenses->get($e->source_id) : null;
            $missing = [];
            if ($l->financial_account_id === null) {
                $missing[] = 'account';
            }
            if ($x && ! $x->vendor) {
                $missing[] = 'vendor';
            }
            if ($x && ! $x->document_id) {
                $missing[] = 'document';
            }
            if ($e->source_type === 'capital') {
                $missing[] = 'method/statement';
            }

            return ['journal' => $e->id, 'date' => $e->entry_date, 'source' => $e->source_type.' #'.$e->source_id, 'memo' => $e->memo, 'debit' => (int) $l->debit, 'credit' => (int) $l->credit,
                'attribution' => $l->financial_account_id ? 'assigned' : 'unassigned', 'candidate' => 'Unresolved — needs a bKash, bank or cash-in-hand record', 'missing' => $missing,
                'status' => $missing === [] ? 'evidenced' : 'evidence needed'];
        });
    }

    /** @return array{unassigned: int, receipts_not_in_ledger: int, projection: int, allocation_possible: bool, allocation_note: string} */
    public static function cashPosition(): array
    {
        $unassigned = AccountingService::balance(null);
        $postedIds = JournalEntry::where('source_type', 'payment')->pluck('source_id');
        $unjournaled = (int) Payment::whereNotIn('id', $postedIds)->get()->filter(fn ($p) => ! AccountingPeriod::where('month', $p->paid_on->format('Y-m'))->exists())->sum('amount');

        return ['unassigned' => $unassigned, 'receipts_not_in_ledger' => $unjournaled, 'projection' => $unassigned + $unjournaled, 'allocation_possible' => $unassigned > 0,
            'allocation_note' => $unassigned > 0 ? 'The existing opening cash allocation can be prepared, but it must account for the entire verified balance from real account records, and the Board must approve it.'
                : 'The existing opening cash allocation cannot be used: it refuses a zero or negative unassigned balance. It becomes available only after receipts reach the ledger (period close or real-time posting) and the balance is positive; no balancing entry is made in the meantime.'];
    }

    /** @return Collection<int, array{lot: SourcingLot, evidence: list<array{label: string, met: bool, detail: string}>, request: mixed}> */
    public static function unresolvedLots(): Collection
    {
        return SourcingLot::whereNull('ownership')->where('status', '!=', 'cancelled')->get()->map(fn (SourcingLot $lot) => ['lot' => $lot, 'evidence' => OwnershipRequests::evidence($lot),
            'request' => $lot->ownershipRequests()->latest('id')->first()]);
    }

    /** @return array<string, int> */
    public static function summary(Collection $receipts): array
    {
        return [
            'received_unallocated' => $receipts->where('received_unallocated', true)->count(),
            'account_verified' => $receipts->where('account_verified', true)->count(),
            'classification_approved' => $receipts->whereIn('classification', ['approved', 'applied'])->count(),
            'posted_to_ledger' => $receipts->where('posted', '!=', 'no')->count(),
            'awaiting_supplier_cost' => $receipts->where('awaiting_supplier_cost', true)->count(),
            'ownership_unresolved' => SourcingLot::whereNull('ownership')->where('status', '!=', 'cancelled')->count(),
            'historical_cash_exception' => AccountingService::balance(null) !== 0 ? 1 : 0,
        ];
    }
}
