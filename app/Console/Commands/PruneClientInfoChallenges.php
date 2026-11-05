<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneClientInfoChallenges extends Command
{
    protected $signature = 'client-api:prune';

    protected $description = 'Delete client information codes that expired or were used more than a day ago (audit rows are kept)';

    public function handle(): int
    {
        $deleted = DB::table('client_info_challenges')->where(fn ($q) => $q->where('expires_at', '<', now()->subDay())->orWhere('consumed_at', '<', now()->subDay()))->delete();
        $this->info("{$deleted} old code(s) removed.");

        return self::SUCCESS;
    }
}
