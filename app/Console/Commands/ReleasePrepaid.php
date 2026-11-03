<?php

namespace App\Console\Commands;

use App\Services\PrepaidService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:release-prepaid {month? : YYYY-MM (defaults to last month)}')]
#[Description('Expense the share of each prepaid cost that has become due up to the month. Idempotent; a month already closed is skipped.')]
class ReleasePrepaid extends Command
{
    public function handle(): int
    {
        $month = $this->argument('month') ?? now()->subMonthNoOverflow()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Use the format YYYY-MM.');

            return self::FAILURE;
        }
        $this->info(PrepaidService::releaseDue($month, null).' release(s) posted for '.$month.'.');

        return self::SUCCESS;
    }
}
