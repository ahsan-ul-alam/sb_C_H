<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CorrectionManifest;
use Illuminate\Console\Command;
use RuntimeException;

class SellbeesCorrect extends Command
{
    protected $signature = 'sellbees:correct
        {action : kinds | prepare | apply}
        {kind? : receipt-classification (prepare only)}
        {--payments= : Comma separated payment ids (prepare)}
        {--out= : Manifest file to write under storage/app (prepare)}
        {--manifest= : Manifest file under storage/app (apply)}
        {--apply : Actually apply. Without it the command only previews and changes nothing}
        {--as= : Email of the Finance user applying (required with --apply)}';

    protected $description = 'Guarded application of corrections that are ALREADY approved in the app. Prepare is read-only; apply previews unless --apply is given. Refuses any stale, edited or unapproved case.';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'kinds' => $this->kinds(),
            'prepare' => $this->prepare(),
            'apply' => $this->applyManifest(),
            default => $this->refuse('Unknown action. Use kinds, prepare or apply.'),
        };
    }

    private function kinds(): int
    {
        $this->line('Supported: receipt-classification (applies an APPROVED receipt review; records a classification, posts nothing).');
        $this->line('Not offered here (use the approval screens, they need Finance and Board people): account activation, opening cash allocation, lot ownership, supplier shortfall, payment linking, ledger corrections.');

        return self::SUCCESS;
    }

    private function prepare(): int
    {
        if ($this->argument('kind') !== 'receipt-classification') {
            return $this->refuse('Give the kind: receipt-classification.');
        }
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('payments'))));
        if ($ids === [] || ! $this->option('out')) {
            return $this->refuse('Give --payments=ID,ID and --out=manifests/name.json.');
        }
        $result = CorrectionManifest::prepareReceiptClassification($ids);
        foreach ($result['blocked'] as $id => $reason) {
            $this->warn("payment {$id}: not included - {$reason}");
        }
        if ($result['manifest'] === null) {
            $this->error('Nothing is ready to apply. No manifest written.');

            return self::FAILURE;
        }
        $file = storage_path('app/'.ltrim((string) $this->option('out'), '/'));
        @mkdir(dirname($file), 0750, true);
        file_put_contents($file, json_encode($result['manifest'], JSON_PRETTY_PRINT));
        foreach ($result['manifest']['cases'] as $c) {
            $this->line('payment '.$c['payment_id'].'  '.number_format($c['amount'] / 100, 2).'  '.$c['nature'].'  fingerprint '.substr($c['fingerprint'], 0, 12));
        }
        $this->info('Manifest written to '.$file.' (read-only preparation; nothing changed).');

        return self::SUCCESS;
    }

    private function applyManifest(): int
    {
        $file = storage_path('app/'.ltrim((string) $this->option('manifest'), '/'));
        if (! $this->option('manifest') || ! is_file($file)) {
            return $this->refuse('Give --manifest=path of an existing manifest under storage/app.');
        }
        $manifest = (array) json_decode((string) file_get_contents($file), true);
        $commit = (bool) $this->option('apply');
        $user = null;
        if ($commit) {
            $user = User::where('email', (string) $this->option('as'))->first();
            if ($user === null) {
                return $this->refuse('--apply needs --as=<email of an existing Finance user>.');
            }
        }

        try {
            $results = CorrectionManifest::apply($manifest, $user ?? new User, $commit);
        } catch (RuntimeException $e) {
            $this->error('Refused: '.$e->getMessage());

            return self::FAILURE;
        }
        foreach ($results as $r) {
            $this->line('payment '.$r['payment_id'].': '.$r['result']);
        }
        $this->info($commit ? 'Applied. Run sellbees:reconcile to verify.' : 'Preview only. Add --apply --as=<finance email> to apply. Nothing was changed.');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
