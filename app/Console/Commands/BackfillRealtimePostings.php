<?php

namespace App\Console\Commands;

use App\Services\LedgerPostingService;
use App\Services\ReconciliationService;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('finance:backfill-realtime {--apply : Actually post (a dry run is the default)}')]
#[Description('Journal payments and verified revenue adjustments that fall in the real-time window but were never posted. Dry run by default; --apply takes a backup first.')]
class BackfillRealtimePostings extends Command
{
    public function handle(): int
    {
        if (LedgerPostingService::cutover() === null) {
            $this->warn('Real-time posting is off (FINANCE_REALTIME_FROM is empty). Nothing to do.');

            return self::SUCCESS;
        }

        $payments = LedgerPostingService::unpostedPayments();
        $adjustments = LedgerPostingService::unpostedAdjustments();
        $this->info('Cutover month: '.LedgerPostingService::cutover()->format('Y-m'));
        $this->line($payments->count().' payment(s) totalling '.Money::format((int) $payments->sum('amount')).' and '.$adjustments->count().' adjustment(s) are not journaled.');

        if (! $this->option('apply')) {
            $this->warn('Dry run: nothing was posted. Re-run with --apply to post them (a backup is taken first).');

            return self::SUCCESS;
        }

        $this->info('Backup written to '.ReconciliationService::backup());
        DB::transaction(function () use ($payments, $adjustments): void {
            foreach ($payments as $payment) {
                LedgerPostingService::syncPayment($payment, null);
            }
            foreach ($adjustments as $adjustment) {
                LedgerPostingService::postAdjustment($adjustment, null);
            }
        });
        $this->info('Posted '.$payments->count().' payment(s) and '.$adjustments->count().' adjustment(s). Run finance:reconcile to confirm.');

        return self::SUCCESS;
    }
}
