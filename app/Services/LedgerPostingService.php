<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\RevenueAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Journals customer money the moment it moves, from the configured cutover month onwards (finance.realtime_from).
 * Earlier months keep the original behaviour: PeriodProcessor books them once, at close. The two never overlap, because
 * period close skips every month at or after the cutover.
 *
 * - A payment received is booked as earned income (Dr cash account / Cr income), exactly as period close treated it.
 * - A verified ADVANCE marking reclassifies that income as a liability (Dr income / Cr customer.advances), dated the payment.
 * - A verified RECOGNITION moves the liability back to income. A verified REFUND pays cash out of income or the liability.
 * - Editing or voiding a posted payment reverses its entry and, for an edit, posts the new figures (versioned source types).
 */
class LedgerPostingService
{
    /** First day of the first month that is journaled in real time, or null when real-time posting is off. */
    public static function cutover(): ?CarbonImmutable
    {
        $configured = self::configuredCutover();
        $earliest = self::earliestRealtimeMonth();
        $floor = self::firstMonthAfterBookedPeriods();

        // Sticky: once something was journaled in real time, those months stay real-time even if the setting is later
        // removed or moved later (period close would otherwise book the same money a second time).
        $base = $configured ?? $earliest;
        if ($base !== null && $earliest !== null && $base->gt($earliest)) {
            $base = $earliest;
        }
        // Never earlier than the month after the last period already booked at close: those months are final, and
        // posting them again in real time would double count.
        if ($base !== null && $floor !== null && $base->lt($floor)) {
            $base = $floor;
        }

        return $base;
    }

    /** The setting as written, normalised to the first day of a month, or null when it is empty. */
    public static function configuredCutover(): ?CarbonImmutable
    {
        $raw = config('finance.realtime_from');
        if (! $raw) {
            return null;
        }
        $date = CarbonImmutable::parse($raw)->startOfDay();

        return $date->day === 1 ? $date : $date->startOfMonth()->addMonth();
    }

    private static function earliestRealtimeMonth(): ?CarbonImmutable
    {
        $date = JournalEntry::where(fn ($q) => $q->where('source_type', 'payment')->orWhere('source_type', 'like', 'payment\_v%')->orWhere('source_type', 'revenue'))
            ->min('entry_date');

        return $date ? CarbonImmutable::parse($date)->startOfMonth() : null;
    }

    private static function firstMonthAfterBookedPeriods(): ?CarbonImmutable
    {
        $month = AccountingPeriod::whereIn('id', JournalEntry::where('source_type', 'period')->pluck('source_id'))->max('month');

        return $month ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay()->addMonth() : null;
    }

    public static function isRealtime(string $date): bool
    {
        $cutover = self::cutover();

        return $cutover !== null && $date >= $cutover->toDateString();
    }

    /** @param  string  $month  YYYY-MM */
    public static function isRealtimeMonth(string $month): bool
    {
        return self::isRealtime($month.'-01');
    }

    /** Source type of the nth posting of a record: the first keeps the plain name, later ones are versioned. */
    public static function typeFor(string $base, int $version): string
    {
        return $version <= 1 ? $base : $base.'_v'.$version;
    }

    private static function inLedgerCurrency(Payment $payment): bool
    {
        return $payment->project()->value('currency') === config('finance.currency');
    }

    private static function livePosting(Payment $payment): ?JournalEntry
    {
        $version = (int) $payment->posting_version;
        if ($version < 1) {
            return null;
        }
        $entry = JournalEntry::where('source_type', self::typeFor('payment', $version))->where('source_id', $payment->id)->first();

        return $entry && ! $entry->isReversed() ? $entry : null;
    }

    /**
     * Bring the journal in line with a payment that was just created or edited. Safe to call repeatedly: it does
     * nothing when the posted entry already matches the payment.
     */
    public static function syncPayment(Payment $payment, ?int $userId): void
    {
        if ((int) $payment->posting_version === 0 && self::cutover() === null) {
            return;
        }
        if (! self::inLedgerCurrency($payment)) {
            return;
        }
        DB::transaction(function () use ($payment, $userId) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $date = $payment->paid_on->toDateString();
            $wanted = self::isRealtime($date);
            $live = self::livePosting($payment);
            $version = (int) $payment->posting_version;
            $postedAccount = $live?->lines()->where('account', 'cash')->value('financial_account_id');
            // A payment with no account of its own keeps the account its live entry was posted to. It is never moved to whatever account is the
            // default today (that would silently redirect historical money when the default changes, or an account is retired). Only a payment that has
            // never been journaled falls back to the default, as before.
            $accountId = $payment->financial_account_id
                ? (int) $payment->financial_account_id
                : ($live ? ($postedAccount === null ? null : (int) $postedAccount) : AccountingService::resolveAccountId(null));

            if ($live && $wanted && (int) $live->lines()->sum('debit') === (int) $payment->amount
                && $live->entry_date->toDateString() === $date
                && ($postedAccount === null ? null : (int) $postedAccount) === $accountId) {
                return;
            }
            if ($live) {
                Ledger::reverse($live, $live->entry_date->toDateString(), 'Reversal of payment #'.$payment->id.' (v'.max(1, $version).')', 'payment_rev'.$version, $payment->id, $userId);
                $version++;
            }
            if ($wanted) {
                $version = max($version, $live ? $version : $version + 1);
                Ledger::post($date, 'Customer payment #'.$payment->id, self::typeFor('payment', $version), $payment->id, [
                    ['cash', null, (int) $payment->amount, 0, $accountId],
                    // From the accrual cutover a customer's money is a liability until the revenue is earned (AccrualRevenue); before it, the cash-basis booking stands.
                    [AccrualRevenue::covers($date) ? 'customer.advances' : 'income', null, 0, (int) $payment->amount],
                ], $userId);
                if (AccrualRevenue::covers($date)) {
                    if (AccrualRevenue::balances($payment->project()->firstOrFail())['advances'] < 0) {
                        throw new RuntimeException('This payment has already been applied to revenue earned: reverse that first, a payment cannot be reduced below what has been applied.');
                    }
                    AccrualRevenue::settle($payment->project()->firstOrFail(), $userId, $date, 'pay'.$payment->id.'v'.$version);
                }
            }
            DB::table('payments')->where('id', $payment->id)->update(['posting_version' => $version]);
        });
    }

    /** Reverse the live posting of a payment that is about to be voided (deleted). */
    public static function voidPayment(Payment $payment, ?int $userId): void
    {
        $live = self::livePosting($payment);
        if (! $live) {
            return;
        }
        $version = (int) $payment->posting_version;
        if (AccrualRevenue::covers($live->entry_date->toDateString()) && AccrualRevenue::balances($payment->project()->firstOrFail())['advances'] - (int) $payment->amount < 0) {
            throw new RuntimeException('This payment has already been applied to revenue earned: reverse that first, it cannot be voided.');
        }
        Ledger::reverse($live, $live->entry_date->toDateString(), 'Void of payment #'.$payment->id, 'payment_rev'.$version, $payment->id, $userId);
        DB::table('payments')->where('id', $payment->id)->update(['posting_version' => $version + 1]);
    }

    /** Journal a verified advance / recognition / refund when its event date is in the real-time window. */
    public static function postAdjustment(RevenueAdjustment $adjustment, ?int $userId): void
    {
        $payment = $adjustment->payment()->firstOrFail();
        if (! self::inLedgerCurrency($payment)) {
            return;
        }
        $date = $adjustment->kind === 'advance' ? $payment->paid_on->toDateString() : $adjustment->effective_on->toDateString();
        if (! self::isRealtime($date)) {
            return;
        }
        if (AccrualRevenue::covers($date)) {
            throw new RuntimeException('From the accrual cutover customer revenue is recognised through revenue events (earned, credit note, refund); an advance or recognition marking would count it twice.');
        }
        $amount = (int) $adjustment->amount;
        $accountId = AccountingService::resolveAccountId($adjustment->financial_account_id);

        $lines = match (true) {
            $adjustment->kind === 'advance' => [['income', null, $amount, 0], ['customer.advances', null, 0, $amount]],
            $adjustment->kind === 'recognition' => [['customer.advances', null, $amount, 0], ['income', null, 0, $amount]],
            $adjustment->kind === 'refund' && $adjustment->against === 'advance' => [['customer.advances', null, $amount, 0], ['cash', null, 0, $amount, $accountId]],
            $adjustment->kind === 'refund' => [['income', null, $amount, 0], ['cash', null, 0, $amount, $accountId]],
            default => throw new RuntimeException('Unknown adjustment kind.'),
        };
        if ($adjustment->kind === 'refund') {
            AccountingService::assertCanSpend($accountId, $amount);
        }

        Ledger::post($date, ucfirst($adjustment->kind).' for payment #'.$payment->id, 'revenue', $adjustment->id, $lines, $userId);
    }

    /**
     * Payments in the real-time window that have no live journal entry yet (recorded before the cutover was switched on,
     * or while it was off). Used by the backfill command and the reconciliation report.
     *
     * @return Collection<int, Payment>
     */
    public static function unpostedPayments(): Collection
    {
        $cutover = self::cutover();
        if (! $cutover) {
            return collect();
        }

        return Payment::query()->whereHas('project', fn ($q) => $q->where('currency', config('finance.currency')))
            ->whereDate('paid_on', '>=', $cutover)->orderBy('id')->get()
            ->filter(fn (Payment $p) => self::livePosting($p) === null)->values();
    }

    /**
     * Verified adjustments whose event date is in the real-time window but which were never journaled.
     *
     * @return Collection<int, RevenueAdjustment>
     */
    public static function unpostedAdjustments(): Collection
    {
        if (! self::cutover()) {
            return collect();
        }

        return RevenueAdjustment::with('payment.project')->where('status', 'verified')->orderBy('id')->get()
            ->filter(function (RevenueAdjustment $a) {
                $date = $a->kind === 'advance' ? $a->payment->paid_on->toDateString() : $a->effective_on->toDateString();

                return $a->payment->project->currency === config('finance.currency') && self::isRealtime($date)
                    && ! JournalEntry::where('source_type', 'revenue')->where('source_id', $a->id)->exists();
            })->values();
    }
}
