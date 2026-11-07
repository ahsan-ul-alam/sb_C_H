<?php

use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\ReceiptClassification;
use App\Models\ReceiptReview;
use App\Services\ReceiptReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
    foreach (glob(storage_path('app/test-manifests/*')) ?: [] as $f) {
        @unlink($f);
    }
});

function scPayment($co, int $amount = 500_000, string $on = '2026-02-10'): Payment
{
    $client = $co->client('Rec Client '.Str::random(4));
    $project = $co->project($client, 'Rec Project');
    $p = new Payment(['amount' => $amount, 'paid_on' => $on, 'method' => 'cash', 'reference' => 'R-'.Str::random(4)]);
    $p->forceFill(['project_id' => $project->id, 'idempotency_key' => (string) Str::uuid()])->save();

    return $p;
}

/** Propose (finance), review (a second Finance user) and approve (Board): the three people the application requires. */
function scApprovedReview($co, Payment $p, string $nature = 'service'): ReceiptReview
{
    Carbon::setTestNow('2026-03-01 10:00:00');
    $review = ReceiptReviews::propose($p, $nature, '2026-02-28', 'Owner confirmed by phone, call log 12', $co->users['finance']);
    ReceiptReviews::review($review, $co->users['multi']);
    ReceiptReviews::approve($review->refresh(), $co->users['board']);

    return $review->refresh();
}

function scRun(string $command, array $args = []): array
{
    $code = Artisan::call($command, $args);

    return [$code, Artisan::output()];
}

it('reconciles read-only, lists exceptions with exact references and changes nothing', function () {
    $co = SellbeesCompany::boot($this);
    $p = scPayment($co);
    $counts = fn () => collect(['payments', 'journal_entries', 'journal_lines', 'receipt_classifications', 'receipt_reviews', 'invoices', 'audit_logs'])->map(fn ($t) => DB::table($t)->count())->all();
    $before = $counts();

    [$code, $out] = scRun('sellbees:reconcile');
    expect($code)->toBe(2)->and($out)->toContain('HX-RECEIPTS-UNCLASSIFIED')->and($out)->toContain('payment '.$p->id)->and($out)->toContain('Nothing was changed')->and($counts())->toBe($before);
    [, $json] = scRun('sellbees:reconcile', ['--json' => true]);
    expect(json_decode($json, true)['counts']['fail'])->toBe(0);
});

it('fails the reconciliation on a real integrity break', function () {
    $co = SellbeesCompany::boot($this);
    $p = scPayment($co);
    $invoice = new Invoice(['number' => 'INV-T-1', 'issued_on' => '2026-02-01', 'currency' => 'BDT']);
    $invoice->forceFill(['project_id' => $p->project_id, 'total' => 900_000, 'amount_paid' => 123, 'status' => 'partial'])->save();

    [$code, $out] = scRun('sellbees:reconcile');
    expect($code)->toBe(1)->and($out)->toContain('[FAIL] INVOICE-PAID')->and($out)->toContain('invoice INV-T-1');
});

it('proves history is untouched with a fingerprint that tolerates new business but not changes', function () {
    $co = SellbeesCompany::boot($this);
    $p = scPayment($co, 500_000, '2026-02-10');
    $co->invest('A', '10000', '2026-02-02');
    scRun('sellbees:reconcile', ['--snapshot' => 'test-manifests/history.json']);

    scPayment($co, 100_000, '2026-02-12');                       // new business after the baseline
    $co->invest('A', '500', '2026-02-13');
    [, $ok] = scRun('sellbees:reconcile', ['--compare' => 'test-manifests/history.json']);
    expect($ok)->not->toContain('[FAIL] HIST');

    DB::table('payments')->where('id', $p->id)->update(['amount' => 500_001]);
    DB::table('journal_lines')->where('id', DB::table('journal_lines')->min('id'))->update(['debit' => DB::raw('debit + 1')]);
    [$code, $bad] = scRun('sellbees:reconcile', ['--compare' => 'test-manifests/history.json']);
    expect($code)->toBe(1)->and($bad)->toContain('[FAIL] HIST-PAYMENTS')->and($bad)->toContain('[FAIL] HIST-JOURNAL');
});

it('prepares only approved cases, previews by default, and applies once through the existing service', function () {
    $co = SellbeesCompany::boot($this);
    $a = scPayment($co);
    $b = scPayment($co, 200_000);
    scApprovedReview($co, $a);
    ReceiptReviews::propose($b, 'service', '2026-02-28', 'phone', $co->users['finance']);            // proposed only: not approved

    [$code, $out] = scRun('sellbees:correct', ['action' => 'prepare', 'kind' => 'receipt-classification', '--payments' => $a->id.','.$b->id, '--out' => 'test-manifests/m.json']);
    expect($code)->toBe(0)->and($out)->toContain('payment '.$b->id.': not included')->and($out)->toContain('payment '.$a->id);

    $journals = JournalEntry::count();
    [$dry, $preview] = scRun('sellbees:correct', ['action' => 'apply', '--manifest' => 'test-manifests/m.json']);
    expect($dry)->toBe(0)->and($preview)->toContain('would apply')->and(ReceiptClassification::count())->toBe(0);

    [$noUser, $needs] = scRun('sellbees:correct', ['action' => 'apply', '--manifest' => 'test-manifests/m.json', '--apply' => true]);
    expect($noUser)->toBe(1)->and($needs)->toContain('--as')->and(ReceiptClassification::count())->toBe(0);
    [$notFinance] = scRun('sellbees:correct', ['action' => 'apply', '--manifest' => 'test-manifests/m.json', '--apply' => true, '--as' => $co->users['employee']->email]);
    expect($notFinance)->toBe(1)->and(ReceiptClassification::count())->toBe(0);

    [$done, $applied] = scRun('sellbees:correct', ['action' => 'apply', '--manifest' => 'test-manifests/m.json', '--apply' => true, '--as' => $co->users['finance']->email]);
    expect($done)->toBe(0)->and($applied)->toContain('applied')->and(ReceiptClassification::where('payment_id', $a->id)->value('nature'))->toBe('service')
        ->and(ReceiptClassification::where('payment_id', $b->id)->exists())->toBeFalse()->and(JournalEntry::count())->toBe($journals)->and(Payment::find($a->id)->amount)->toBe(500_000);

    [$again, $second] = scRun('sellbees:correct', ['action' => 'apply', '--manifest' => 'test-manifests/m.json', '--apply' => true, '--as' => $co->users['finance']->email]);
    expect($again)->toBe(0)->and($second)->toContain('already applied')->and(ReceiptClassification::count())->toBe(1);
});

it('refuses an edited, stale or wrong-state manifest', function () {
    $co = SellbeesCompany::boot($this);
    $p = scPayment($co);
    scApprovedReview($co, $p);
    scRun('sellbees:correct', ['action' => 'prepare', 'kind' => 'receipt-classification', '--payments' => (string) $p->id, '--out' => 'test-manifests/m.json']);
    $path = storage_path('app/test-manifests/m.json');
    $run = fn () => scRun('sellbees:correct', ['action' => 'apply', '--manifest' => 'test-manifests/m.json', '--apply' => true, '--as' => $co->users['finance']->email]);

    $edited = json_decode(file_get_contents($path), true);
    $edited['cases'][0]['nature'] = 'product_sourcing';
    file_put_contents(storage_path('app/test-manifests/edited.json'), json_encode($edited));
    [$c1, $o1] = scRun('sellbees:correct', ['action' => 'apply', '--manifest' => 'test-manifests/edited.json', '--apply' => true, '--as' => $co->users['finance']->email]);
    expect($c1)->toBe(1)->and($o1)->toContain('signature');

    DB::table('payments')->where('id', $p->id)->update(['amount' => 500_100]);                            // the source changed after preparation
    [$c2, $o2] = $run();
    expect($c2)->toBe(1)->and($o2)->toContain('fingerprint mismatch')->and(ReceiptClassification::count())->toBe(0);
    DB::table('payments')->where('id', $p->id)->update(['amount' => 500_000]);

    AccountingPeriod::create(['month' => '2026-02', 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'status' => 'closed']);   // the month was closed meanwhile
    [$c3, $o3] = $run();
    expect($c3)->toBe(1)->and($o3)->toContain('month is closed')->and(ReceiptClassification::count())->toBe(0);
});
