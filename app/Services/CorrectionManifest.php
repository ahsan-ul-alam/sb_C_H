<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\ReceiptReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Guarded application of corrections that ALREADY went through the Finance and Board maker-checker workflow in the application. The command line adds only
 * a mechanical step, protected by a manifest that fingerprints exactly the records it touches: a manifest prepared on one state of the data refuses to run
 * against any other state of those records, while unrelated new business is ignored. The manifest is signed with the application key, so it cannot be edited
 * by hand and is only valid on the server that prepared it. Nothing here approves, posts a journal, moves stock or changes an amount.
 *
 * Supported kind: receipt-classification (applies an APPROVED receipt review, which records a classification and posts nothing). Everything else
 * (account activation, opening cash allocation, lot ownership, supplier shortfall, payment linking) stays in its approval screen and is deliberately not offered here.
 */
class CorrectionManifest
{
    public const KINDS = ['receipt-classification'];

    /** @param  list<int>  $paymentIds @return array{manifest: ?array<string, mixed>, blocked: array<int, string>} */
    public static function prepareReceiptClassification(array $paymentIds): array
    {
        $cases = [];
        $blocked = [];
        foreach (array_values(array_unique($paymentIds)) as $id) {
            $problem = null;
            $case = self::caseFor((int) $id, $problem);
            $case === null || $problem !== null ? $blocked[(int) $id] = $problem ?? 'unknown' : $cases[] = $case;
        }
        if ($cases === []) {
            return ['manifest' => null, 'blocked' => $blocked];
        }
        $body = ['version' => 1, 'kind' => 'receipt-classification', 'prepared_at' => now()->toIso8601String(), 'schema' => self::schema(), 'cases' => $cases];

        return ['manifest' => $body + ['signature' => self::sign($body)], 'blocked' => $blocked];
    }

    /**
     * Every precondition of every case against the LIVE data. Returns the reasons it must not run; empty means safe.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public static function problems(array $manifest): array
    {
        $signature = (string) ($manifest['signature'] ?? '');
        $body = $manifest;
        unset($body['signature']);
        if ($signature === '' || ! hash_equals(self::sign($body), $signature)) {
            return ['The manifest signature is invalid. It was edited, or it was prepared on a different server: prepare it again on this server.'];
        }
        if (($manifest['kind'] ?? null) !== 'receipt-classification') {
            return ['Unsupported manifest kind.'];
        }
        if (! DB::table('migrations')->where('migration', $manifest['schema'] ?? '')->exists() || self::schema() !== ($manifest['schema'] ?? null)) {
            return ['The database schema version differs from the one the manifest was prepared against ('.($manifest['schema'] ?? '?').' vs '.self::schema().').'];
        }
        $problems = [];
        foreach ($manifest['cases'] ?? [] as $case) {
            $problem = null;
            $live = self::caseFor((int) $case['payment_id'], $problem, allowApplied: true);
            if ($live === null || $problem !== null) {
                $problems[] = 'payment '.$case['payment_id'].': '.($problem ?? 'record not found');
            } elseif ($live['fingerprint'] !== $case['fingerprint']) {
                $problems[] = 'payment '.$case['payment_id'].': the record changed since the manifest was prepared (fingerprint mismatch).';
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{payment_id: int, result: string}>
     */
    public static function apply(array $manifest, User $operator, bool $commit): array
    {
        $problems = self::problems($manifest);
        if ($problems !== []) {
            throw new RuntimeException(implode(' | ', $problems));
        }
        if ($commit && ! $operator->hasPermission('reconcile.review')) {
            throw new RuntimeException('The named user may not apply reconciliation corrections (Finance only).');
        }
        $results = [];
        foreach ($manifest['cases'] as $case) {
            $review = ReceiptReview::findOrFail($case['review_id']);
            if ($review->status === 'applied') {
                $results[] = ['payment_id' => (int) $case['payment_id'], 'result' => 'already applied; nothing to do'];

                continue;
            }
            if ($commit) {
                ReceiptReviews::apply($review, $operator);   // locks the review, re-checks it is approved and unclassified, records the classification, posts nothing
            }
            $results[] = ['payment_id' => (int) $case['payment_id'], 'result' => $commit ? 'applied (classification '.$case['nature'].'; no ledger posting)' : 'would apply classification '.$case['nature'].' (no ledger posting)'];
        }

        return $results;
    }

    /** @return array<string, mixed>|null */
    private static function caseFor(int $paymentId, ?string &$problem, bool $allowApplied = false): ?array
    {
        $payment = Payment::find($paymentId);
        if ($payment === null) {
            $problem = 'no such payment';

            return null;
        }
        $review = ReceiptReview::where('payment_id', $paymentId)->first();
        if ($review === null) {
            $problem = 'no owner-confirmed receipt review exists; create and approve it in Finance > Historical Reconciliation first';

            return null;
        }
        $status = $review->status;
        if (! ($status === 'approved' || ($allowApplied && $status === 'applied'))) {
            $problem = 'the review is '.$status.', not approved by the three required people';
        }
        $classified = ReceiptClassification::where('payment_id', $paymentId)->exists();
        if ($classified && $status !== 'applied') {
            $problem = 'the receipt was already classified another way';
        }
        if (AccountingPeriod::where('month', $payment->paid_on->format('Y-m'))->exists()) {
            $problem = 'the payment month is closed; closed periods are never touched from the command line';
        }
        if (JournalEntry::where('source_type', 'payment')->where('source_id', $paymentId)->exists()) {
            $problem = 'the payment is already in the ledger; classification must not be applied on top of a posting';
        }
        $row = ['payment' => [$payment->id, (int) $payment->amount, $payment->paid_on->toDateString(), $payment->project_id, $payment->invoice_id, $payment->method?->value, $payment->financial_account_id],
            'review' => [$review->id, $review->proposed_nature, $review->proposed_by, $review->reviewed_by, $review->approved_by]];

        return ['payment_id' => $paymentId, 'amount' => (int) $payment->amount, 'review_id' => $review->id, 'nature' => $review->proposed_nature, 'fingerprint' => hash('sha256', json_encode($row))];
    }

    private static function schema(): string
    {
        return (string) DB::table('migrations')->orderByDesc('id')->value('migration');
    }

    /** @param  array<string, mixed>  $body */
    private static function sign(array $body): string
    {
        return hash_hmac('sha256', json_encode($body), (string) config('app.key'));
    }
}
