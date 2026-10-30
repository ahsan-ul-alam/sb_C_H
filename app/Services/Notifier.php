<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Allocation;
use App\Models\CapitalEntry;
use App\Models\EmergencyFundRequest;
use App\Models\ExitSettlement;
use App\Models\Expense;
use App\Models\OpeningBatch;
use App\Models\OpeningLine;
use App\Models\Payout;
use App\Models\PolicyVersion;
use App\Models\Resolution;
use App\Models\RevenueAdjustment;
use App\Models\SourcingLot;
use Illuminate\Support\Facades\Log;

/**
 * Business event -> notification map. Hooked to model events, so a notification exists only for a record that
 * was really saved (and, via Notify, only after the transaction commits). Messages never contain amounts.
 * Approval requests go to users who hold the approving permission, never to the person who prepared the item
 * or to the partner a personal payout/salary/settlement is about.
 */
class Notifier
{
    public static function register(): void
    {
        // Simple "someone must verify, then tell the requester" items.
        foreach ([
            [Expense::class, 'expense.verify', 'finance.expenses', 'Expense'],
            [CapitalEntry::class, 'capital.verify', 'finance.capital', 'Investment entry'],
            [RevenueAdjustment::class, 'expense.verify', 'finance.revenue', 'Advance/refund record'],
        ] as [$model, $permission, $route, $label]) {
            self::listen($model, 'created', function ($m) use ($permission, $route, $label) {
                if (($m->status ?? 'pending') !== 'pending') {
                    return; // already verified under a standing policy: nothing for a person to do
                }
                Notify::send(Notify::withPermission($permission, [$m->created_by]), $label.' awaiting verification', 'A new '.strtolower($label).' needs a Board verification.', $route, 'approval', strtolower(class_basename($m)).':'.$m->id.':verify');
            });
            self::listen($model, 'updated', function ($m) use ($route, $label) {
                if ($m->wasChanged('status') && in_array($m->status, ['verified', 'rejected'], true)) {
                    Notify::send(Notify::user($m->created_by), $label.' '.$m->status, 'Your '.strtolower($label).' was '.$m->status.'.', $route, 'result', strtolower(class_basename($m)).':'.$m->id.':'.$m->status);
                }
            });
        }

        self::listen(AccountingPeriod::class, 'created', fn ($p) => $p->closed_by === null ? null : Notify::send(Notify::withPermission('period.verify', [$p->closed_by]), 'Period awaiting verification', 'Period '.$p->month.' was closed and needs verification.', 'finance.periods.show', 'approval', 'period:'.$p->id.':verify', [$p]));
        self::listen(AccountingPeriod::class, 'updated', function ($p) {
            if ($p->wasChanged('status') && $p->status === 'verified' && $p->verified_by !== null) {
                Notify::send(Notify::user($p->closed_by)->merge(Notify::withPermission('allocation.prepare', [$p->verified_by])), 'Period verified', 'Period '.$p->month.' is verified and ready for allocation.', 'finance.periods.show', 'result', 'period:'.$p->id.':verified', [$p]);
            }
        });

        self::listen(Allocation::class, 'created', fn ($a) => $a->prepared_by === null ? null : Notify::send(Notify::withPermission('allocation.approve', [$a->prepared_by]), 'Allocation awaiting approval', 'A '.$a->kind.' allocation needs Board approval.', 'finance.periods.show', 'approval', 'allocation:'.$a->id.':approve', [$a->accounting_period_id]));
        self::listen(Allocation::class, 'updated', function ($a) {
            if (! $a->wasChanged('status')) {
                return;
            }
            if ($a->status === 'approved' && $a->approved_by !== null) {
                Notify::send(Notify::withPermission('allocation.post')->merge(Notify::user($a->prepared_by)), 'Allocation approved', 'An approved allocation is ready to post.', 'finance.periods.show', 'approval', 'allocation:'.$a->id.':post', [$a->accounting_period_id]);
            }
            if ($a->status === 'posted' && $a->kind === 'profit') {
                $partnerIds = $a->lines()->whereNotNull('partner_id')->pluck('partner_id')->unique();
                $users = $partnerIds->flatMap(fn ($id) => Notify::partnerUsers($id));
                Notify::send($users, 'Profit entitlement recorded', 'Your approved profit entitlement for this period was recorded.', 'finance.me', 'partner', 'allocation:'.$a->id.':posted');
            }
        });

        self::listen(Payout::class, 'created', fn ($p) => Notify::send(
            Notify::withPermission('payout.approve', [$p->requested_by, ...Notify::partnerUsers($p->partner_id)->pluck('id')->all()]),
            'Payout awaiting approval', 'A partner payout request needs Board approval.', 'finance.payouts', 'approval', 'payout:'.$p->id.':approve'));
        self::listen(Payout::class, 'updated', function ($p) {
            if (! $p->wasChanged('status')) {
                return;
            }
            $own = Notify::partnerUsers($p->partner_id);
            if ($p->status === 'approved') {
                Notify::send(Notify::withPermission('payout.pay', $own->pluck('id')->all()), 'Payout approved', 'An approved payout is ready to be paid.', 'finance.payouts', 'approval', 'payout:'.$p->id.':pay');
            }
            if (in_array($p->status, ['approved', 'rejected', 'paid'], true)) {
                Notify::send($own->merge(Notify::user($p->requested_by)), 'Payout '.$p->status, 'Your payout request is now '.$p->status.'.', 'finance.payouts', 'partner', 'payout:'.$p->id.':'.$p->status);
            }
        });

        self::listen(Resolution::class, 'created', fn ($r) => Notify::send(
            Notify::withPermission('resolution.decide', [$r->requested_by, ...Notify::partnerUsers($r->partner_id)->pluck('id')->all()]),
            'Board resolution needs a decision', 'A resolution is waiting for a Board decision.', 'finance.resolutions', 'approval', 'resolution:'.$r->id.':decide'));
        self::listen(Resolution::class, 'updated', function ($r) {
            if (! $r->wasChanged('status')) {
                return;
            }
            $own = Notify::partnerUsers($r->partner_id);
            if (in_array($r->status, ['approved', 'rejected', 'paid'], true)) {
                Notify::send(Notify::user($r->requested_by)->merge($own), 'Resolution '.$r->status, 'A resolution you raised or that concerns you is now '.$r->status.'.', 'finance.resolutions', 'result', 'resolution:'.$r->id.':'.$r->status);
            }
            if ($r->status === 'approved' && $r->type === 'partner_salary') {
                Notify::send(Notify::withPermission('resolution.pay', $own->pluck('id')->all()), 'Approved salary ready to pay', 'A Board-approved partner salary is ready to be paid.', 'finance.resolutions', 'approval', 'resolution:'.$r->id.':pay');
            }
            if ($r->status === 'approved' && $r->type === 'exit_request') {
                Notify::send(Notify::withPermission('exit.prepare', $own->pluck('id')->all()), 'Partner exit request approved', 'An approved exit request is ready for settlement calculation.', 'finance.exits', 'approval', 'resolution:'.$r->id.':exit');
            }
        });

        self::listen(PolicyVersion::class, 'created', fn ($p) => Notify::send(Notify::withPermission('policy.approve', [$p->created_by]), 'Policy awaiting approval', 'A draft policy needs Board approval.', 'finance.policies', 'approval', 'policy:'.$p->id.':approve'));
        self::listen(PolicyVersion::class, 'updated', function ($p) {
            if ($p->wasChanged('approved_at') && $p->approved_at) {
                Notify::send(Notify::withPermission('policy.approve', [$p->approved_by]), 'Profit-ratio confirmation pending', 'The policy is approved; the profit-fund ratio still needs separate confirmation.', 'finance.policies', 'approval', 'policy:'.$p->id.':confirm');
            }
            if ($p->wasChanged('profit_confirmed_at') && $p->profit_confirmed_at) {
                Notify::send(Notify::withPermission('allocation.prepare'), 'Policy confirmed', 'The policy and profit-fund ratio are confirmed.', 'finance.policies', 'result', 'policy:'.$p->id.':confirmed');
            }
        });

        // Opening balances: ask for approval once the first line exists; report approval/posting back.
        self::listen(OpeningLine::class, 'created', function ($line) {
            $batch = $line->batch;
            if ($batch && $batch->lines()->count() === 1) {
                Notify::send(Notify::withPermission('opening.approve', [$batch->prepared_by]), 'Opening balances awaiting approval', 'An opening-balance batch needs Board approval.', 'finance.opening', 'approval', 'opening:'.$batch->id.':approve');
            }
        });
        self::listen(OpeningBatch::class, 'updated', function ($b) {
            if ($b->wasChanged('status') && in_array($b->status, ['approved', 'posted'], true)) {
                Notify::send(Notify::user($b->prepared_by), 'Opening balances '.$b->status, 'Your opening-balance batch is '.$b->status.'.', 'finance.opening', 'result', 'opening:'.$b->id.':'.$b->status);
            }
        });

        self::listen(ExitSettlement::class, 'created', fn ($e) => Notify::send(
            Notify::withPermission('exit.approve', [$e->prepared_by, ...Notify::partnerUsers($e->partner_id)->pluck('id')->all()]),
            'Exit settlement awaiting approval', 'A partner exit settlement needs Board approval.', 'finance.exits', 'approval', 'exit:'.$e->id.':approve'));
        self::listen(ExitSettlement::class, 'updated', function ($e) {
            $own = Notify::partnerUsers($e->partner_id);
            if ($e->wasChanged('status') && $e->status === 'approved') {
                Notify::send(Notify::withPermission('exit.pay', $own->pluck('id')->all()), 'Exit settlement approved', 'An approved exit settlement is ready for payment.', 'finance.exits', 'approval', 'exit:'.$e->id.':pay');
                Notify::send($own, 'Your settlement status changed', 'Your exit settlement was approved.', 'finance.me', 'partner', 'exit:'.$e->id.':approved');
            }
            if ($e->wasChanged('payment_status') && $e->payment_status === 'paid') {
                Notify::send($own->merge(Notify::user($e->prepared_by)), 'Exit settlement paid', 'The exit settlement payment was recorded.', $own->isNotEmpty() ? 'finance.me' : 'finance.exits', 'partner', 'exit:'.$e->id.':paid');
            }
            if ($e->wasChanged('status') && $e->status === 'cancelled') {
                Notify::send($own->merge(Notify::user($e->prepared_by)), 'Exit settlement cancelled', 'An unpaid exit settlement was cancelled for recalculation.', 'finance.exits', 'partner', 'exit:'.$e->id.':cancelled');
            }
        });

        // Sourcing: Board decides fund coverage (never the requester); Finance utilises once approved.
        self::listen(EmergencyFundRequest::class, 'created', fn ($r) => $r->status !== 'pending' ? null : Notify::send(
            Notify::withPermission('sourcing.fund.approve', [$r->requested_by]),
            'Emergency Fund request awaiting approval', 'A sourcing loss coverage request needs a Board decision.', 'sourcing.lots.show', 'approval', 'fundreq:'.$r->id.':approve', [$r->sourcing_lot_id]));
        self::listen(EmergencyFundRequest::class, 'updated', function ($r) {
            if (! $r->wasChanged('status')) {
                return;
            }
            if ($r->status === 'approved') {
                Notify::send(Notify::withPermission('sourcing.fund.utilize', [$r->decided_by]), 'Emergency Fund coverage approved', 'Approved coverage is ready to be utilised.', 'sourcing.lots.show', 'approval', 'fundreq:'.$r->id.':utilise', [$r->sourcing_lot_id]);
            }
            if (in_array($r->status, ['approved', 'rejected', 'utilised', 'reversed'], true)) {
                Notify::send(Notify::user($r->requested_by), 'Emergency Fund request '.$r->status, 'Your coverage request is now '.$r->status.'.', 'sourcing.lots.show', 'result', 'fundreq:'.$r->id.':'.$r->status, [$r->sourcing_lot_id]);
            }
        });
        self::listen(SourcingLot::class, 'updated', function ($l) {
            if ($l->wasChanged('status') && $l->status === 'priced') {
                Notify::send(Notify::withPermission('sourcing.price.approve', [$l->priced_by]), 'Sourcing pricing awaiting approval', $l->number.' is priced and needs approval.', 'sourcing.lots.show', 'approval', 'lot:'.$l->id.':price', [$l->id]);
            }
        });
    }

    /**
     * Register a model-event listener that can never break the business action that triggered it.
     * A failure is logged as an ERROR (not swallowed silently); the saved record and its transaction are unaffected.
     */
    private static function listen(string $model, string $event, \Closure $handler): void
    {
        $model::$event(function ($record) use ($handler, $model, $event): void {
            try {
                $handler($record);
            } catch (\Throwable $e) {
                Log::error('Notification hook failed', ['model' => $model, 'event' => $event, 'id' => $record->getKey(), 'error' => $e->getMessage()]);
            }
        });
    }
}
