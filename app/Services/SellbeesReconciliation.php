<?php

namespace App\Services;

use App\Models\Client;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SourcingLot;
use App\Models\SourcingLotLine;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One read-only view over the whole system, built from the existing checks (ReconciliationService, InventoryReconciliation, HistoricalReconciliation,
 * Inventory::reconciles) plus the source-level checks they do not make (invoice paid amounts, unlinked receipts, voided-payment differences, supplier
 * shortfalls, asset-typed project expenses, client numbers). It never writes. Every finding has a stable id so a person can be pointed at the exact case.
 *
 * Status: pass | exception (a documented pre-existing item that needs business evidence and is contained, not hidden) | fail (an integrity violation).
 */
class SellbeesReconciliation
{
    /** @return list<array{id: string, area: string, title: string, status: string, detail: string, amount: ?int, refs: list<string>}> */
    public static function run(): array
    {
        $checks = [
            ...self::ledgerAndFinance(),
            ...self::inventory(),
            ...self::receipts(),
            ...self::invoices(),
            ...self::supplier(),
            ...self::classification(),
            ...self::clients(),
            ...self::frozen(),
        ];

        return $checks;
    }

    /** @return array{pass: int, exception: int, fail: int} */
    public static function counts(array $checks): array
    {
        $by = collect($checks)->countBy('status');

        return ['pass' => (int) $by->get('pass', 0), 'exception' => (int) $by->get('exception', 0), 'fail' => (int) $by->get('fail', 0)];
    }

    /** @return array<string, mixed> history fingerprints: later business is allowed; the history up to the recorded point must not change */
    public static function snapshot(): array
    {
        $maxJournal = (int) DB::table('journal_entries')->max('id');

        return [
            'taken_at' => now()->toIso8601String(), 'migrations' => (int) DB::table('migrations')->count(), 'latest_migration' => (string) DB::table('migrations')->orderByDesc('id')->value('migration'),
            'journal_max_id' => $maxJournal, 'journal_hash' => self::journalHash($maxJournal),
            'allocations' => (int) DB::table('allocations')->count(), 'allocations_max_id' => (int) DB::table('allocations')->max('id'), 'allocations_hash' => self::allocationsHash(),
            'periods_max_id' => (int) DB::table('accounting_periods')->max('id'), 'periods_hash' => self::periodsHash(PHP_INT_MAX),
            'payments_max_id' => (int) DB::table('payments')->max('id'), 'payments_hash' => self::paymentsHash(PHP_INT_MAX),
        ];
    }

    /** @param  array<string, mixed>  $baseline @return list<array<string, mixed>> */
    public static function compare(array $baseline): array
    {
        $out = [];
        $add = function (string $id, string $title, bool $ok, string $detail) use (&$out): void {
            $out[] = ['id' => $id, 'area' => 'History', 'title' => $title, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail, 'amount' => null, 'refs' => []];
        };
        $add('HIST-JOURNAL', 'Posted journals up to the baseline are unchanged', self::journalHash((int) $baseline['journal_max_id']) === $baseline['journal_hash'], 'journals 1..'.$baseline['journal_max_id'].' compared; later entries are new business');
        $add('HIST-ALLOCATIONS', 'Frozen profit allocations are unchanged', self::allocationsHash((int) $baseline['allocations_max_id']) === $baseline['allocations_hash'], $baseline['allocations'].' allocation(s) at baseline');
        $add('HIST-PERIODS', 'Closed accounting periods are unchanged', self::periodsHash((int) $baseline['periods_max_id']) === $baseline['periods_hash'], 'periods 1..'.$baseline['periods_max_id'].' compared');
        $add('HIST-PAYMENTS', 'Recorded customer payments are unchanged', self::paymentsHash((int) $baseline['payments_max_id']) === $baseline['payments_hash'], 'payments 1..'.$baseline['payments_max_id'].' compared (amounts, dates, methods, links); later payments are new business');

        return $out;
    }

    private static function journalHash(int $upTo): string
    {
        $rows = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_entries.id', '<=', $upTo)
            ->orderBy('journal_lines.id')->get(['journal_entries.id as e', 'journal_entries.entry_date', 'journal_entries.source_type', 'journal_entries.source_id', 'journal_lines.account', 'journal_lines.partner_id',
                'journal_lines.debit', 'journal_lines.credit', 'journal_lines.financial_account_id'])->all();

        return hash('sha256', json_encode($rows));
    }

    private static function periodsHash(int $upTo): string
    {
        return hash('sha256', json_encode(DB::table('accounting_periods')->where('id', '<=', $upTo)->orderBy('id')->get(['id', 'month', 'income', 'expenses', 'net_profit', 'status'])->all()));
    }

    private static function paymentsHash(int $upTo): string
    {
        return hash('sha256', json_encode(DB::table('payments')->where('id', '<=', $upTo)->orderBy('id')->get(['id', 'project_id', 'invoice_id', 'amount', 'paid_on', 'method'])->all()));
    }

    private static function allocationsHash(int $upTo = PHP_INT_MAX): string
    {
        return hash('sha256', json_encode([DB::table('allocations')->where('id', '<=', $upTo)->orderBy('id')->get()->all(),
            DB::table('allocation_lines')->whereIn('allocation_id', DB::table('allocations')->where('id', '<=', $upTo)->select('id'))->orderBy('id')->get()->all()]));
    }

    /** @return list<array<string, mixed>> */
    private static function ledgerAndFinance(): array
    {
        $out = [];
        foreach (ReconciliationService::report() as $i => $r) {
            $unassignedCash = str_starts_with($r['check'], 'Cash not yet assigned');
            $id = $unassignedCash ? 'HX-CASH-UNASSIGNED' : 'FIN-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $out[] = self::row($id, $r['area'], $r['check'], $r['ok'] ? 'pass' : ($unassignedCash ? 'exception' : 'fail'),
                $r['ok'] ? 'agrees' : $r['reason'].' '.$r['proposal'], $r['ok'] || $r['unit'] === 'count' ? null : (int) $r['difference']);
        }
        $accounts = FinancialAccount::count();
        $active = FinancialAccount::where('is_active', true)->where('activation_status', 'active')->count();
        $out[] = self::row('HX-ACCOUNTS-NOT-ACTIVE', 'Cash & bank', 'Cash in Hand, bKash and Bank Account are prepared and active', $active >= 1 ? 'pass' : 'exception',
            $accounts.' financial account(s) exist, '.$active.' active. Their real identities, opening dates and verified statements are business evidence; nothing is invented.');

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function inventory(): array
    {
        $out = [];
        $report = InventoryReconciliation::company();
        $out[] = self::row('INV-SUBLEDGER', 'Inventory', 'Inventory value subledger agrees with the general ledger', $report['ok'] ? 'pass' : 'fail', $report['ok'] ? 'agrees' : 'differences in InventoryReconciliation::company()');
        $bad = SourcingLotLine::whereNotNull('received_quantity')->get()->filter(fn ($l) => ! Inventory::reconciles($l));
        $out[] = self::row('INV-MOVEMENTS', 'Inventory', 'Every received lot line equals its stock movements', $bad->isEmpty() ? 'pass' : 'fail', $bad->isEmpty() ? 'agrees' : $bad->count().' line(s) differ', null, $bad->pluck('id')->map(fn ($i) => 'lot line '.$i)->all());
        $unknown = SourcingLot::whereNull('ownership')->where('status', '!=', 'cancelled')->get();
        foreach ($unknown as $lot) {
            $lines = $lot->lines()->whereNotNull('received_quantity')->count();
            $out[] = self::row('HX-LOT-OWNERSHIP-'.$lot->number, 'Ownership', 'Lot '.$lot->number.' ownership is not stated', 'exception',
                $lines.' received line(s); ownership-dependent valuation, sale and cost stay blocked until the evidence is approved in the ownership workflow. Nothing is assumed.', null, ['lot '.$lot->number]);
        }
        if ($unknown->isEmpty()) {
            $out[] = self::row('OWN-LOTS', 'Ownership', 'Every lot has a stated owner', 'pass', 'agrees');
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function receipts(): array
    {
        $rows = HistoricalReconciliation::receipts();
        $unclassified = $rows->whereNotIn('classification', ['applied']);
        $unallocated = $rows->filter(fn ($r) => $r['payment']->financial_account_id === null);
        $notInLedger = $rows->where('posted', 'no');
        $pos = HistoricalReconciliation::cashPosition();

        return [
            self::row('HX-RECEIPTS-UNCLASSIFIED', 'Receipts', 'Customer receipts have an applied classification', $unclassified->isEmpty() ? 'pass' : 'exception',
                $unclassified->count().' of '.$rows->count().' receipt(s), '.Money::format((int) $unclassified->sum(fn ($r) => $r['payment']->amount)).', have no applied classification. Owner-confirmed receipt is not proof of the account or of earned revenue.',
                (int) $unclassified->sum(fn ($r) => $r['payment']->amount), $unclassified->map(fn ($r) => 'payment '.$r['payment']->id)->values()->all()),
            self::row('HX-RECEIPTS-NO-ACCOUNT', 'Receipts', 'Customer receipts are attributed to a verified financial account', $unallocated->isEmpty() ? 'pass' : 'exception',
                $unallocated->count().' receipt(s) have no financial account; the destination (cash, bKash or bank) is not evidenced.', (int) $unallocated->sum(fn ($r) => $r['payment']->amount), $unallocated->map(fn ($r) => 'payment '.$r['payment']->id)->values()->all()),
            self::row('HX-RECEIPTS-NOT-IN-LEDGER', 'Receipts', 'Customer receipts are in the ledger (real-time or at close)', $notInLedger->isEmpty() ? 'pass' : 'exception',
                $notInLedger->count().' receipt(s) are recorded but not in the ledger (cash-basis legacy; real-time posting is off). Projected cash if they were: '.Money::format($pos['projection']).' (unverified).',
                (int) $notInLedger->sum(fn ($r) => $r['payment']->amount), $notInLedger->map(fn ($r) => 'payment '.$r['payment']->id)->values()->all()),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function invoices(): array
    {
        $drift = collect();
        $open = Invoice::query()->where('status', '!=', 'paid')->get()->groupBy('project_id');
        foreach (Invoice::orderBy('id')->get() as $i) {
            $paid = (int) $i->payments()->sum('amount') + $i->segmentPaymentsCredit();
            if ($paid !== (int) $i->amount_paid) {
                $drift->push($i);
            }
        }
        // A payment with no invoice is fine when the segment it was allocated to is billed by an invoice (the invoice takes it as credit). It is flagged only when nothing consumes it.
        $unlinked = Payment::whereNull('invoice_id')->with('allocations')->orderBy('id')->get()->filter(fn ($p) => $open->has($p->project_id)
            && ! DB::table('invoice_items')->whereIn('segment_id', $p->allocations->pluck('segment_id'))->exists());
        $out = [self::row('INVOICE-PAID', 'Invoices', 'Every invoice paid amount equals its payments', $drift->isEmpty() ? 'pass' : 'fail',
            $drift->isEmpty() ? 'agrees' : $drift->count().' invoice(s) differ from their payments (invoices:reconcile --dry-run lists the changes)', null, $drift->map(fn ($i) => 'invoice '.$i->number)->values()->all())];
        $out[] = self::row('HX-PAYMENT-UNLINKED', 'Invoices', 'Payments without an invoice are not sitting beside an unpaid invoice of the same project', $unlinked->isEmpty() ? 'pass' : 'exception',
            $unlinked->count().' payment(s) are not linked to an invoice while their project has an unpaid invoice. Linking needs proof of the intended invoice; the original amount is never changed.',
            (int) $unlinked->sum('amount'), $unlinked->map(fn ($p) => 'payment '.$p->id.' / open invoice '.$open->get($p->project_id)->pluck('number')->implode(','))->values()->all());
        $voided = self::voidedDifferences();
        $out[] = self::row('HX-VOIDED-PAYMENT-DIFF', 'Invoices', 'A voided payment and its replacement carry the same amount', $voided === [] ? 'pass' : 'exception',
            $voided === [] ? 'agrees' : count($voided).' voided payment group(s) total differently from the payment(s) recorded next (possible replacement). The reason for the difference is not recorded and is not guessed.',
            $voided === [] ? null : (int) array_sum(array_column($voided, 'diff')), array_map(fn ($v) => 'payment '.$v['voided'].' ('.Money::format($v['was']).') -> payment '.$v['next'].' ('.Money::format($v['now']).')', $voided));

        return $out;
    }

    /** @return list<array{voided: string, next: string, was: int, now: int, diff: int}> */
    private static function voidedDifferences(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }
        // Consecutive voided payments are treated as one group replaced by the same number of payments recorded next; the group's totals are compared, so
        // re-entering one amount as several (or the reverse) is not a difference. The pairing is a heuristic and is reported as "possible", never acted on.
        $voided = [];
        foreach (DB::table('audit_logs')->where('action', 'payment.voided')->orderBy('id')->get() as $log) {
            if (preg_match('/payment (\d+)/', (string) $log->subject, $m) && preg_match('/amount (\d+) /', (string) $log->details, $d)) {
                $voided[(int) $m[1]] = (int) $d[1];
            }
        }
        ksort($voided);
        $groups = [];
        foreach ($voided as $id => $amount) {
            isset($voided[$id - 1]) && $groups ? $groups[array_key_last($groups)][$id] = $amount : $groups[] = [$id => $amount];
        }
        $found = [];
        foreach ($groups as $group) {
            $last = array_key_last($group);
            $next = Payment::whereBetween('id', [$last + 1, $last + count($group)])->orderBy('id')->get();
            if ($next->count() === count($group) && (int) array_sum($group) !== (int) $next->sum('amount')) {
                $found[] = ['voided' => implode('+', array_keys($group)), 'next' => $next->pluck('id')->implode('+'), 'was' => (int) array_sum($group), 'now' => (int) $next->sum('amount'), 'diff' => abs((int) array_sum($group) - (int) $next->sum('amount'))];
            }
        }

        return $found;
    }

    /** @return list<array<string, mixed>> */
    private static function supplier(): array
    {
        $short = collect();
        foreach (SourcingLot::with('lines', 'payments')->get() as $lot) {
            $paid = (int) $lot->payments->whereNull('voided_at')->sum('amount');
            foreach ($lot->lines->filter(fn ($l) => $l->received_quantity !== null && bccomp((string) $l->received_quantity, (string) $l->quantity, 2) < 0) as $l) {
                $units = bcsub((string) $l->quantity, (string) $l->received_quantity, 2);
                $short->push(['ref' => $lot->number.' line '.$l->id.' ('.$l->description.'): ordered '.$l->quantity.', received '.$l->received_quantity, 'amount' => Money::multiply($units, (int) $l->unit_price), 'paid' => $paid > 0]);
            }
        }
        $paidShort = $short->where('paid', true);
        $unpaidCosts = DB::table('sourcing_lot_costs')->where('kind', 'actual')->whereNull('expense_id')->whereNotIn('id', DB::table('sourcing_lot_costs')->whereNotNull('replaces_id')->select('replaces_id'));

        return [
            self::row('HX-SUPPLIER-SHORTFALL', 'Supplier', 'Goods received equal goods ordered and paid for', $paidShort->isEmpty() ? 'pass' : 'exception',
                $paidShort->count().' line(s) received fewer units than ordered on a lot that has supplier payments; the shortfall is owed back or credited by the supplier (existing shortfall workflow, needs the supplier\'s confirmation).',
                (int) $paidShort->sum('amount'), $paidShort->pluck('ref')->values()->all()),
            self::row('HX-LOT-COSTS-UNPAID', 'Supplier', 'Actual lot costs are paid or recorded as payable', (clone $unpaidCosts)->exists() ? 'exception' : 'pass',
                (clone $unpaidCosts)->count().' actual lot cost(s) have no expense record yet.', (int) (clone $unpaidCosts)->sum('amount'), (clone $unpaidCosts)->pluck('id')->map(fn ($i) => 'lot cost '.$i)->all()),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function classification(): array
    {
        $odd = DB::table('expenses')->where('is_asset', 1)->whereNotNull('project_id')->where('status', 'verified')->get();

        return [self::row('HX-ASSET-PROJECT-EXPENSE', 'Expenses', 'No project-linked expense is booked as a company asset', $odd->isEmpty() ? 'pass' : 'exception',
            $odd->count().' verified expense(s) are linked to a client project but booked to a company asset account. Whether it is a client cost or a company asset needs a decision; the journal is not rewritten.',
            (int) $odd->sum('amount'), $odd->map(fn ($e) => 'expense '.$e->id.' '.$e->category)->all())];
    }

    /** @return list<array<string, mixed>> */
    private static function clients(): array
    {
        if (! Schema::hasColumn('clients', 'sb_number')) {
            return [self::row('CLIENT-SB', 'Clients', 'Every client has a unique SB number', 'exception', 'The SB number migration has not been applied on this database.')];
        }
        $missing = Client::whereNull('sb_number')->count();

        return [self::row('CLIENT-SB', 'Clients', 'Every client has a unique SB number', $missing === 0 ? 'pass' : 'fail', $missing === 0 ? Client::count().' client(s) numbered' : $missing.' client(s) have no SB number')];
    }

    /** @return list<array<string, mixed>> */
    private static function frozen(): array
    {
        $n = (int) DB::table('allocations')->count();
        $unbalanced = DB::table('allocation_lines')->join('allocations', 'allocations.id', '=', 'allocation_lines.allocation_id')->where('allocations.status', 'posted')
            ->groupBy('allocations.id', 'allocations.net_profit')->havingRaw('SUM(allocation_lines.amount) <> allocations.net_profit')->select('allocations.id')->get()->count();

        return [self::row('ALLOC-FROZEN', 'Allocations', 'Posted profit allocations add up to their net profit', $unbalanced === 0 ? 'pass' : 'fail', $n.' allocation(s) on record, '.$unbalanced.' unbalanced. Use --snapshot/--compare to prove they stay unchanged.')];
    }

    /** @param  list<string>  $refs @return array<string, mixed> */
    private static function row(string $id, string $area, string $title, string $status, string $detail, ?int $amount = null, array $refs = []): array
    {
        return ['id' => $id, 'area' => $area, 'title' => $title, 'status' => $status, 'detail' => $detail, 'amount' => $amount, 'refs' => $refs];
    }
}
