<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\InventoryHandover;
use App\Models\InventoryHandoverReturn;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Project;
use App\Models\RevenueEvent;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Earned-revenue accounting for customers, forward-only from a configured, future-dated cutover (finance.accrual_revenue_from, empty everywhere today).
 *
 *   a customer payment            Dr cash / Cr customer.advances            a liability, never income by itself (LedgerPostingService, from the cutover)
 *   revenue earned, invoiced      Dr customer.advances (what the customer already paid) and Dr receivables (the rest) / Cr income
 *   revenue earned, not invoiced  the same with Dr assets.contract (a contract asset) in place of the receivable; a later "bill" moves it to a receivable
 *   advance settling a receivable Dr customer.advances / Cr receivables   (automatic, once per payment)
 *   credit note / sales return    Dr income.returns / Cr receivables (then the contract asset), any excess Cr customer.advances: a credit owed to the customer
 *   customer refund               Dr customer.advances / Cr cash, out of what the customer is owed, on a named active account
 * Principal or agent comes from the project's classification and its evidence. An unclassified project is classification_required and nothing is recognised for it. An
 * agent never recognises the client's merchandise as sales: only commission or service fees. A principal sale of goods needs the verified transfer of control it rests on
 * (an approved handover, which already carried the cost of goods sold), used once. Every event is proposed by Finance and approved by a different Board member.
 * The positions of a project are plain sums of its approved events, and each event is one balanced journal, so they reconcile to the ledger by construction and by check.
 */
class AccrualRevenue
{
    /** The first day the engine applies to, or null while it is off. Never before the real-time posting cutover, so a month is never on two bases. */
    public static function cutover(): ?string
    {
        $raw = config('finance.accrual_revenue_from');
        $realtime = LedgerPostingService::cutover();
        if (! $raw || ! $realtime) {
            return null;
        }
        $date = CarbonImmutable::parse($raw)->startOfDay();
        $date = $date->day === 1 ? $date : $date->startOfMonth()->addMonth();

        return ($date->lt($realtime) ? $realtime : $date)->toDateString();
    }

    public static function covers(string $date): bool
    {
        $cutover = self::cutover();

        return $cutover !== null && $date >= $cutover;
    }

    /** @return array{collected: int, advances: int, receivable: int, contract_asset: int, revenue: int} */
    public static function balances(Project $project, ?int $exceptId = null, bool $includePending = false): array
    {
        $cutover = self::cutover();
        $events = RevenueEvent::where('project_id', $project->id)->whereIn('status', $includePending ? ['approved', 'pending'] : ['approved'])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId));
        $collected = $cutover ? (int) Payment::where('project_id', $project->id)->whereDate('paid_on', '>=', $cutover)->sum('amount') : 0;
        $sum = fn (string $column) => (int) (clone $events)->sum($column);

        return ['collected' => $collected, 'advances' => $collected + $sum('advance_delta'), 'receivable' => $sum('receivable_delta'), 'contract_asset' => $sum('contract_asset_delta'), 'revenue' => $sum('revenue_delta')];
    }

    public static function setArrangement(Project $project, string $arrangement, string $evidence, User $user): void
    {
        DB::transaction(function () use ($project, $arrangement, $evidence, $user) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (! $user->can('revenue.event.propose')) {
                throw new RuntimeException('Only Finance can classify how Sellbees acts on a project.');
            }
            if (! in_array($arrangement, ['principal', 'agent'], true) || mb_strlen(trim($evidence)) < 10) {
                throw new RuntimeException('State principal or agent, with the contract terms, control and fulfilment responsibility it rests on.');
            }
            if (RevenueEvent::where('project_id', $project->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw new RuntimeException('Revenue has already been proposed or recognised for this project; the classification cannot change under it.');
            }
            $project->forceFill(['revenue_arrangement' => $arrangement, 'revenue_arrangement_evidence' => trim($evidence)])->save();
            Audit::log('revenue.arrangement', 'project '.$project->id, $arrangement.': '.trim($evidence));
        });
    }

    /**
     * @param  array<string, mixed>  $data  amount (minor units), effective_on, evidence_reference, note, and per kind: basis, invoice_id, related_event_id, inventory_handover_id,
     *                                      inventory_handover_return_id, financial_account_id
     */
    public static function propose(User $user, Project $project, string $kind, array $data, string $key): RevenueEvent
    {
        if ($existing = RevenueEvent::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        try {
            return DB::transaction(function () use ($user, $project, $kind, $data, $key) {
                $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                if (! $user->can('revenue.event.propose')) {
                    throw new RuntimeException('Only Finance can propose a revenue event.');
                }
                $c = self::compute($project, $kind, $data, null);
                $event = RevenueEvent::create(['project_id' => $project->id, 'invoice_id' => $data['invoice_id'] ?? null, 'related_event_id' => $data['related_event_id'] ?? null, 'inventory_handover_id' => $data['inventory_handover_id'] ?? null,
                    'inventory_handover_return_id' => $data['inventory_handover_return_id'] ?? null, 'kind' => $kind, 'basis' => $c['basis'], 'arrangement' => $c['arrangement'], 'amount' => (int) $data['amount'], 'effective_on' => $data['effective_on'],
                    'evidence_reference' => isset($data['evidence_reference']) ? trim((string) $data['evidence_reference']) : null, 'note' => $data['note'] ?? null, 'financial_account_id' => $data['financial_account_id'] ?? null,
                    'prepared_by' => $user->id, 'idempotency_key' => $key] + $c['deltas']);
                Audit::log('revenue.proposed', 'project '.$project->id, '#'.$event->id.' '.$kind.' '.$event->amount);

                return $event->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return RevenueEvent::where('idempotency_key', $key)->firstOrFail();
        }
    }

    /**
     * The deltas of an event and every rule that must hold, on the facts as they are now (pending events count, so two proposals cannot together exceed an invoice or a balance).
     *
     * @return array{deltas: array<string, int>, basis: ?string, arrangement: ?string}
     */
    private static function compute(Project $project, string $kind, array $data, ?int $exceptId): array
    {
        $amount = (int) ($data['amount'] ?? 0);
        $on = (string) ($data['effective_on'] ?? '');
        if (! in_array($kind, ['recognition', 'bill', 'credit_note', 'refund'], true) || $amount <= 0) {
            throw new RuntimeException('Choose the kind of event and an amount above zero.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > today()->toDateString()) {
            throw new RuntimeException('Give the date it happened; it cannot be in the future.');
        }
        if (! self::covers($on)) {
            throw new RuntimeException('The accrual revenue engine does not apply to that date: it starts at the configured cutover, and earlier customer money stays on the cash basis it was booked under.');
        }
        if ($project->currency !== config('finance.currency')) {
            throw new RuntimeException('Only projects in the ledger currency can be recognised here.');
        }
        $arrangement = $project->revenue_arrangement;
        if ($arrangement === null && $kind !== 'refund') {
            throw new RuntimeException('classification_required: state whether Sellbees is principal or agent on this project, with the contract evidence, before any revenue is recognised for it.');
        }
        $deltas = ['revenue_delta' => 0, 'receivable_delta' => 0, 'contract_asset_delta' => 0, 'advance_delta' => 0, 'cash_delta' => 0];
        $b = self::balances($project, $exceptId);
        $basis = $data['basis'] ?? null;
        $pending = fn () => RevenueEvent::where('project_id', $project->id)->whereIn('status', ['pending', 'approved'])->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId));
        $invoice = null;
        if (! empty($data['invoice_id'])) {
            $invoice = Invoice::find($data['invoice_id']);
            if (! $invoice || (int) $invoice->project_id !== (int) $project->id || $invoice->voided_at !== null) {
                throw new RuntimeException('That invoice does not belong to this project or has been voided.');
            }
        }

        if ($kind === 'recognition') {
            if (mb_strlen(trim((string) ($data['evidence_reference'] ?? ''))) < 5) {
                throw new RuntimeException('Give the evidence that the performance obligation was satisfied (a delivery, completion or acceptance record).');
            }
            $allowed = $arrangement === 'agent' ? ['commission', 'service_fee'] : ['sale', 'service'];
            if (! in_array($basis, $allowed, true)) {
                throw new RuntimeException($arrangement === 'agent'
                    ? 'Sellbees acts as agent on this project: the client\'s merchandise proceeds belong to the client, and only a commission or service fee is Sellbees\' revenue.'
                    : 'Sellbees acts as principal on this project: recognise a sale of goods or a service.');
            }
            if ($basis === 'sale') {
                $h = InventoryHandover::find($data['inventory_handover_id'] ?? 0);
                if (! $h || $h->status !== 'approved' || (int) $h->project_id !== (int) $project->id) {
                    throw new RuntimeException('A principal sale of goods rests on the verified transfer of control of those goods to this project: link its approved handover.');
                }
                if ($pending()->where('inventory_handover_id', $h->id)->where('kind', 'recognition')->exists()) {
                    throw new RuntimeException('That handover is already the basis of another sale: its revenue is recognised once.');
                }
            }
            $charges = (int) $project->segments()->sum('amount');
            if ($charges <= 0) {
                throw new RuntimeException('No contract amount (charge) is recorded for this project, so there is nothing to recognise revenue against.');
            }
            $recognised = (int) $pending()->whereIn('kind', ['recognition'])->sum('amount') - (int) $pending()->where('kind', 'credit_note')->sum('amount');
            if ($recognised + $amount > $charges) {
                throw new RuntimeException('Revenue recognised ('.Money::format($recognised + $amount).') would exceed the contract amounts recorded for the project ('.Money::format($charges).').');
            }
            if ($invoice) {
                $billed = (int) $pending()->where('invoice_id', $invoice->id)->whereIn('kind', ['recognition', 'bill'])->sum('amount');
                if ($billed + $amount > (int) $invoice->total) {
                    throw new RuntimeException('That invoice has only '.Money::format(max(0, (int) $invoice->total - $billed)).' left to recognise against.');
                }
            }
            $adv = min($amount, max(0, $b['advances']));
            $deltas = ['revenue_delta' => $amount, 'advance_delta' => -$adv, 'receivable_delta' => $invoice ? $amount - $adv : 0, 'contract_asset_delta' => $invoice ? 0 : $amount - $adv, 'cash_delta' => 0];
        } elseif ($kind === 'bill') {
            if (! $invoice) {
                throw new RuntimeException('Choose the invoice the unbilled revenue is billed on.');
            }
            if ($amount > $b['contract_asset']) {
                throw new RuntimeException('Only '.Money::format(max(0, $b['contract_asset'])).' of recognised revenue is still unbilled.');
            }
            $billed = (int) $pending()->where('invoice_id', $invoice->id)->whereIn('kind', ['recognition', 'bill'])->sum('amount');
            if ($billed + $amount > (int) $invoice->total) {
                throw new RuntimeException('That invoice has only '.Money::format(max(0, (int) $invoice->total - $billed)).' left to bill.');
            }
            $deltas['contract_asset_delta'] = -$amount;
            $deltas['receivable_delta'] = $amount;
        } elseif ($kind === 'credit_note') {
            $related = RevenueEvent::where('id', $data['related_event_id'] ?? 0)->where('project_id', $project->id)->where('kind', 'recognition')->where('status', 'approved')->first();
            if (! $related) {
                throw new RuntimeException('A credit note reverses revenue that was recognised: choose that approved recognition.');
            }
            if (mb_strlen(trim((string) ($data['evidence_reference'] ?? ''))) < 5) {
                throw new RuntimeException('Give the reason and evidence (a return note, a price adjustment agreement).');
            }
            $credited = (int) $pending()->where('kind', 'credit_note')->where('related_event_id', $related->id)->sum('amount');
            if ($amount > (int) $related->amount - $credited) {
                throw new RuntimeException('Only '.Money::format(max(0, (int) $related->amount - $credited)).' of that revenue can still be credited.');
            }
            if (! empty($data['inventory_handover_return_id'])) {
                $ret = InventoryHandoverReturn::find($data['inventory_handover_return_id']);
                if (! $ret || $ret->status !== 'approved' || ! $ret->handover || (int) $ret->handover->project_id !== (int) $project->id || ($related->inventory_handover_id && (int) $ret->inventory_handover_id !== (int) $related->inventory_handover_id)) {
                    throw new RuntimeException('That return is not an approved return of the goods this revenue rests on.');
                }
            }
            $basis = $related->basis;
            $inv = $related->invoice_id !== null;
            $r = $inv ? min($amount, max(0, $b['receivable'])) : 0;
            $contract = $inv ? 0 : min($amount, max(0, $b['contract_asset']));
            $deltas = ['revenue_delta' => -$amount, 'receivable_delta' => -$r, 'contract_asset_delta' => -$contract, 'advance_delta' => $amount - $r - $contract, 'cash_delta' => 0];
        } else {
            if ($amount > $b['advances']) {
                throw new RuntimeException('Only '.Money::format(max(0, $b['advances'])).' is held for this customer; a refund cannot exceed what is owed to them.');
            }
            if (mb_strlen(trim((string) ($data['evidence_reference'] ?? ''))) < 5) {
                throw new RuntimeException('Give the bank reference or evidence of the refund.');
            }
            if ($pending()->where('kind', 'refund')->where('evidence_reference', trim((string) $data['evidence_reference']))->exists()) {
                throw new RuntimeException('That refund reference is already used: a refund is recorded once.');
            }
            if (empty($data['financial_account_id']) && FinancialAccount::query()->exists()) {
                throw new RuntimeException('Choose the financial account the refund is paid from.');
            }
            AccountingService::assertActive($data['financial_account_id'] ?? null);
            $deltas = ['advance_delta' => -$amount, 'cash_delta' => -$amount, 'revenue_delta' => 0, 'receivable_delta' => 0, 'contract_asset_delta' => 0];
        }

        return ['deltas' => $deltas, 'basis' => $basis, 'arrangement' => $arrangement];
    }

    /** @return list<array<int, mixed>> */
    private static function lines(RevenueEvent $e, ?int $accountId): array
    {
        $lines = [];
        $signed = function (string $account, int $debitPositive) use (&$lines) {
            if ($debitPositive !== 0) {
                $lines[] = $debitPositive > 0 ? [$account, null, $debitPositive, 0] : [$account, null, 0, -$debitPositive];
            }
        };
        $signed($e->revenue_delta >= 0 ? 'income' : 'income.returns', -(int) $e->revenue_delta);
        $signed('receivables', (int) $e->receivable_delta);
        $signed('assets.contract', (int) $e->contract_asset_delta);
        $signed('customer.advances', -(int) $e->advance_delta);
        if ($e->cash_delta !== 0) {
            $lines[] = $e->cash_delta > 0 ? ['cash', null, (int) $e->cash_delta, 0, $accountId] : ['cash', null, 0, -(int) $e->cash_delta, $accountId];
        }
        if (collect($lines)->sum(2) !== collect($lines)->sum(3)) {
            throw new RuntimeException('This event does not balance.');
        }

        return $lines;
    }

    public static function decide(RevenueEvent $event, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($event, $approve, $user, $note) {
            $project = Project::whereKey(RevenueEvent::findOrFail($event->id)->project_id)->lockForUpdate()->firstOrFail();
            $event = RevenueEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status !== 'pending') {
                throw new RuntimeException('Already decided.');
            }
            if ($event->prepared_by === $user->id) {
                throw new RuntimeException('You cannot approve an event you proposed.');
            }
            if (! $user->can('revenue.event.approve')) {
                throw new RuntimeException('Only a Board member authorised for revenue decisions can decide this.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give a reason for rejecting.');
                }
                $event->update(['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim($note)]);

                return;
            }
            if (JournalEntry::where('source_type', 'revenue_event')->where('source_id', $event->id)->exists()) {
                throw new RuntimeException('This event has already been posted.');
            }
            $c = self::compute($project, $event->kind, ['amount' => $event->amount, 'effective_on' => $event->effective_on->toDateString(), 'basis' => $event->basis, 'invoice_id' => $event->invoice_id, 'related_event_id' => $event->related_event_id,
                'inventory_handover_id' => $event->inventory_handover_id, 'inventory_handover_return_id' => $event->inventory_handover_return_id, 'evidence_reference' => $event->evidence_reference, 'financial_account_id' => $event->financial_account_id], $event->id);
            $event->fill($c['deltas']);
            $closed = AccountingService::isClosed($event->effective_on->toDateString());
            $date = $closed ? today()->toDateString() : $event->effective_on->toDateString();
            AccountingService::assertOpen($date, 'this revenue event');
            $accountId = $event->kind === 'refund' ? AccountingService::activeAccountId($event->financial_account_id) : null;
            if ($event->kind === 'refund') {
                AccountingService::assertCanSpend($accountId, (int) $event->amount);
            }
            $entry = Ledger::post($date, ucfirst(str_replace('_', ' ', $event->kind)).' (project '.$project->id.', event #'.$event->id.($event->basis ? ', '.$event->basis : '').')'.($closed ? ' - dated '.$event->effective_on->toDateString().' in a closed period; recorded in the open period, flagged for review' : ''),
                'revenue_event', $event->id, self::lines($event, $accountId), $user->id);
            $event->save();
            $event->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => trim((string) $note) ?: null, 'posted_on' => $date, 'prior_period' => $closed, 'journal_entry_id' => $entry->id]);
            Audit::log('revenue.approved', 'project '.$project->id, '#'.$event->id.' '.$event->kind.' '.$event->amount);
            self::settle($project, $user->id, $date, 'ev'.$event->id);
        });
    }

    /** An advance the customer paid settles a receivable of the same project, once per source: Dr customer.advances / Cr receivables. */
    public static function settle(Project $project, ?int $userId, string $date, string $key): ?RevenueEvent
    {
        if (RevenueEvent::where('idempotency_key', 'app:'.$key)->exists()) {
            return null;
        }
        try {
            return DB::transaction(function () use ($project, $userId, $date, $key) {
                $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                $b = self::balances($project);
                $x = min($b['advances'], $b['receivable']);
                if ($x <= 0) {
                    return null;
                }
                $closed = AccountingService::isClosed($date);
                $posted = $closed ? today()->toDateString() : $date;
                $event = RevenueEvent::create(['project_id' => $project->id, 'kind' => 'application', 'amount' => $x, 'receivable_delta' => -$x, 'advance_delta' => -$x, 'effective_on' => $date, 'posted_on' => $posted, 'prior_period' => $closed,
                    'status' => 'approved', 'prepared_by' => null, 'approved_by' => $userId, 'approved_at' => now(), 'idempotency_key' => 'app:'.$key]);
                $entry = Ledger::post($posted, 'Customer advance applied to the receivable (project '.$project->id.', event #'.$event->id.')', 'revenue_event', $event->id, self::lines($event, null), $userId);
                $event->update(['journal_entry_id' => $entry->id]);

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** A void is recorded, never a delete, and only for an invoice nothing has been recognised, credited or paid against. */
    public static function voidInvoice(Invoice $invoice, string $reason, User $user): void
    {
        DB::transaction(function () use ($invoice, $reason, $user) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $user->can('revenue.event.propose')) {
                throw new RuntimeException('Only Finance can void an invoice.');
            }
            if ($invoice->voided_at !== null) {
                throw new RuntimeException('This invoice is already void.');
            }
            if (mb_strlen(trim($reason)) < 5) {
                throw new RuntimeException('Give the reason for voiding the invoice.');
            }
            if (RevenueEvent::where('invoice_id', $invoice->id)->whereIn('status', ['pending', 'approved'])->exists() || Payment::where('invoice_id', $invoice->id)->exists() || (int) $invoice->amount_paid > 0) {
                throw new RuntimeException('Revenue, a credit or a payment is recorded against this invoice: reverse those first, a void cannot hide them.');
            }
            $invoice->forceFill(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => trim($reason)])->save();
            Audit::log('invoice.voided', 'invoice '.$invoice->number, trim($reason));
        });
    }

    /**
     * Every approved event carried once by its journal, and the ledger's movement since the cutover on each account agreeing with the events (and the payments that
     * fund customer advances). Differences are reported, never corrected.
     *
     * @return array{problems: list<string>, ok: bool, receivable: int, contract_asset: int, advances: int, revenue: int, returns: int}
     */
    public static function reconcile(): array
    {
        $cutover = self::cutover();
        $problems = [];
        if ($cutover === null) {
            return ['problems' => [], 'ok' => true, 'receivable' => 0, 'contract_asset' => 0, 'advances' => 0, 'revenue' => 0, 'returns' => 0];
        }
        $events = RevenueEvent::where('status', 'approved')->get();
        foreach ($events as $e) {
            $entries = JournalEntry::where('source_type', 'revenue_event')->where('source_id', $e->id)->pluck('id');
            if ($entries->count() !== 1) {
                $problems[] = 'Event #'.$e->id.' has '.$entries->count().' journal entries; it must have exactly one.';
            }
        }
        $net = fn (string $account) => (int) JournalLine::query()->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')->where('journal_lines.account', $account)->whereDate('journal_entries.entry_date', '>=', $cutover)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) - COALESCE(SUM(journal_lines.credit),0) as n')->value('n');
        $collected = (int) Payment::whereDate('paid_on', '>=', $cutover)->whereHas('project', fn ($q) => $q->where('currency', config('finance.currency')))->sum('amount');
        $expect = ['receivables' => (int) $events->sum('receivable_delta'), 'assets.contract' => (int) $events->sum('contract_asset_delta'), 'customer.advances' => -($collected + (int) $events->sum('advance_delta')),
            'income' => -(int) $events->where('revenue_delta', '>', 0)->sum('revenue_delta'), 'income.returns' => -(int) $events->where('revenue_delta', '<', 0)->sum('revenue_delta')];
        foreach ($expect as $account => $want) {
            if ($net($account) !== $want) {
                $problems[] = 'The ledger movement on '.$account.' since the cutover ('.$net($account).') differs from the events ('.$want.').';
            }
        }

        return ['problems' => $problems, 'ok' => $problems === [], 'receivable' => $expect['receivables'], 'contract_asset' => $expect['assets.contract'], 'advances' => -$expect['customer.advances'], 'revenue' => -$expect['income'], 'returns' => $expect['income.returns']];
    }
}
