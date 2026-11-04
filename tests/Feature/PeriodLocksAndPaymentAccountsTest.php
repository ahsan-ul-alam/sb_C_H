<?php

use App\Models\AccountingPeriod;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBatch;
use App\Models\Payment;
use App\Models\RevenueAdjustment;
use App\Services\AccountingService;
use App\Services\PeriodProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\SellbeesCompany;

require_once __DIR__.'/../Support/helpers.php';

uses(RefreshDatabase::class);

/*
| Task C (opening balances and closed periods) and Task D (customer payment accounts). Amounts are integer paisa. "Today" is 2026-11-10 and
| real-time posting starts on 2026-11-01.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-10');
    config(['finance.realtime_from' => '2026-11-01', 'finance.completeness_from' => null]);
    $this->co = SellbeesCompany::boot($this);
    $this->finance = $this->co->users['finance'];
});

afterEach(fn () => Carbon::setTestNow());

function opPostBatch(string $type, string $asOf, array $lines, array $extra = []): OpeningBatch
{
    $co = test()->co;
    $co->post('finance', 'finance.opening.store', ['type' => $type, 'as_of' => $asOf, ...$extra])->assertSessionHasNoErrors()->assertSessionMissing('error');
    $batch = OpeningBatch::latest('id')->firstOrFail();
    foreach ($lines as [$kind, $amount]) {
        $co->post('finance', 'finance.opening.lines', ['kind' => $kind, 'amount' => $amount], $batch)->assertSessionHasNoErrors();
    }
    $co->post('board', 'finance.opening.approve', [], $batch)->assertSessionHasNoErrors();

    return $batch->refresh();
}

function closeMonth(string $month): void
{
    AccountingPeriod::create(['month' => $month, 'income' => 0, 'expenses' => 0, 'net_profit' => 0, 'closed_by' => test()->finance->id]);
}

it('refuses an opening balance dated into a closed month, and a correction is a new, referenced batch in an open month', function () {
    $opening = opPostBatch('opening', '2026-01-01', [['cash', '500000']]);
    $this->co->post('finance', 'finance.opening.post', [], $opening)->assertSessionHas('status');
    $original = JournalEntry::where('source_type', 'opening')->where('source_id', $opening->id)->firstOrFail();
    $snapshot = JournalLine::where('journal_entry_id', $original->id)->orderBy('id')->get()->toArray();
    closeMonth('2026-01');

    // A second opening balance cannot be made at all, and nothing can be dated into the closed month.
    $this->co->post('finance', 'finance.opening.store', ['type' => 'adjustment', 'as_of' => '2026-01-20', 'corrects_batch_id' => $opening->id, 'correction_reason' => 'Bank statement showed 5,010'])->assertSessionHas('error');
    // A correction must name the posted batch it corrects, and say why.
    $this->co->post('finance', 'finance.opening.store', ['type' => 'adjustment', 'as_of' => '2026-11-05', 'correction_reason' => 'Bank statement showed 5,010'])->assertSessionHas('error');
    $this->co->post('finance', 'finance.opening.store', ['type' => 'adjustment', 'as_of' => '2026-11-05', 'corrects_batch_id' => $opening->id, 'correction_reason' => 'x'])->assertSessionHas('error');
    expect(OpeningBatch::count())->toBe(1);

    // The proper way: a new adjustment dated in an open month, with the reference and the reason.
    $fix = opPostBatch('adjustment', '2026-11-05', [['cash', '1000']], ['corrects_batch_id' => $opening->id, 'correction_reason' => 'Bank statement showed 5,010']);
    $this->co->post('finance', 'finance.opening.post', [], $fix)->assertSessionHas('status');

    $entry = JournalEntry::where('source_type', 'opening')->where('source_id', $fix->id)->firstOrFail();
    expect($fix->corrects_batch_id)->toBe($opening->id)->and($fix->correction_reason)->toBe('Bank statement showed 5,010')
        ->and($entry->entry_date->toDateString())->toBe('2026-11-05')->and($entry->id)->not->toBe($original->id)
        ->and(JournalLine::where('journal_entry_id', $original->id)->orderBy('id')->get()->toArray())->toBe($snapshot) // the original entry is untouched
        ->and(ledgerAudit()['unbalanced'])->toBe(0)->and(ledgerAudit()['duplicates'])->toBe(0);
});

it('refuses to post a prepared batch once its month has been closed', function () {
    $opening = opPostBatch('opening', '2026-01-01', [['cash', '500000']]);
    $this->co->post('finance', 'finance.opening.post', [], $opening)->assertSessionHas('status');
    $fix = opPostBatch('adjustment', '2026-11-05', [['cash', '1000']], ['corrects_batch_id' => $opening->id, 'correction_reason' => 'Bank statement showed 5,010']);
    closeMonth('2026-11'); // November closes between approval and posting

    $this->co->post('finance', 'finance.opening.post', [], $fix)->assertSessionHas('error');
    expect($fix->refresh()->status)->toBe('approved')->and(JournalEntry::where('source_type', 'opening')->where('source_id', $fix->id)->exists())->toBeFalse();
});

it('still closes a month normally with corrections and real-time postings in the ledger', function () {
    $opening = opPostBatch('opening', '2026-01-01', [['cash', '500000']]);
    $this->co->post('finance', 'finance.opening.post', [], $opening)->assertSessionHas('status');
    opPostBatch('adjustment', '2026-10-05', [['cash', '1000']], ['corrects_batch_id' => $opening->id, 'correction_reason' => 'Bank statement showed 5,010']);
    $fix = OpeningBatch::latest('id')->firstOrFail();
    $this->co->post('finance', 'finance.opening.post', [], $fix)->assertSessionHas('status');

    Carbon::setTestNow('2026-11-12');
    $period = PeriodProcessor::close('2026-10', $this->finance);

    expect($period->month)->toBe('2026-10')->and(AccountingPeriod::where('month', '2026-10')->exists())->toBeTrue()->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('requires an active account for a new real-time customer payment and never assumes the default', function () {
    $default = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $retired = FinancialAccount::factory()->create(['name' => 'Retired wallet']);
    $retired->update(['is_active' => false]);
    $project = $this->co->project($this->co->client('Client'), 'Project');
    $segment = $this->co->segment($project, 'Build', '100000');
    $pay = fn (array $extra) => $this->co->post('finance', 'segments.payments.store', ['amount' => '1000', 'paid_on' => '2026-11-06', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(), ...$extra], $segment);

    $pay([])->assertSessionHasErrors('financial_account_id');                                   // no account: the default is not assumed
    $pay(['financial_account_id' => $retired->id])->assertSessionHasErrors('financial_account_id'); // inactive
    expect(Payment::count())->toBe(0)->and(JournalEntry::where('source_type', 'payment')->count())->toBe(0);

    $pay(['financial_account_id' => $default->id])->assertSessionHasNoErrors();
    $payment = Payment::firstOrFail();
    $line = JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->firstOrFail()->lines->firstWhere('account', 'cash');
    expect($payment->financial_account_id)->toBe($default->id)->and($line->financial_account_id)->toBe($default->id)->and(AccountingService::balance($default->id))->toBe(100_000);
});

it('does not ask for an account where none applies: before the cutover, and for a company without accounts', function () {
    $project = $this->co->project($this->co->client('Client'), 'Project');
    $segment = $this->co->segment($project, 'Build', '100000');

    // No financial account exists yet: a payment is recorded without one, as before.
    $this->co->paySegment($segment, '1000', '2026-11-06')->assertSessionHasNoErrors();
    expect(Payment::count())->toBe(1);

    // Accounts exist now; a payment dated before the cutover is recognised at period close with no account, so none is demanded.
    FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->co->post('finance', 'segments.payments.store', ['amount' => '500', 'paid_on' => '2026-10-15', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()], $segment)->assertSessionHasNoErrors();
    expect(Payment::count())->toBe(2)->and(JournalEntry::where('source_type', 'payment')->count())->toBe(1);
});

it('keeps a historical payment where it was booked when it is edited, and does not follow a new default', function () {
    $project = $this->co->project($this->co->client('Client'), 'Project');
    $segment = $this->co->segment($project, 'Build', '100000');
    $this->co->paySegment($segment, '1000', '2026-11-06')->assertSessionHasNoErrors(); // booked to the unassigned (legacy) cash: no account existed
    $payment = Payment::firstOrFail();
    $first = JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->firstOrFail();
    expect($first->lines->firstWhere('account', 'cash')->financial_account_id)->toBeNull();

    // An account is created and becomes the default; the old payment is then edited (account field untouched).
    $default = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $this->co->as('finance')->put(route('payments.update', $payment), ['amount' => '1200', 'paid_on' => '2026-11-06', 'method' => 'cash'])->assertSessionHasNoErrors();

    $live = JournalEntry::where('source_id', $payment->id)->where('source_type', 'payment_v2')->firstOrFail(); // the first posting was reversed, the edit posted version 2
    expect($live->lines->firstWhere('account', 'cash')->financial_account_id)->toBeNull()   // still the legacy cash, not silently moved to the new default
        ->and(AccountingService::balance($default->id))->toBe(0)->and(AccountingService::balance(null))->toBe(120_000)
        ->and(ledgerAudit()['unbalanced'])->toBe(0);
});

it('requires an active account for a real-time refund and refuses to fall back to the default', function () {
    $default = FinancialAccount::factory()->asDefault()->create(['name' => 'Office Cash']);
    $retired = FinancialAccount::factory()->create(['name' => 'Retired wallet']);
    $retired->update(['is_active' => false]);
    $project = $this->co->project($this->co->client('Client'), 'Project');
    $segment = $this->co->segment($project, 'Build', '100000');
    $this->co->post('finance', 'segments.payments.store', ['amount' => '1000', 'paid_on' => '2026-11-06', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'financial_account_id' => $default->id], $segment)->assertSessionHasNoErrors();
    $payment = Payment::firstOrFail();
    $refund = ['kind' => 'refund', 'against' => 'revenue', 'amount' => '100', 'effective_on' => '2026-11-08', 'reason' => 'Returned goods'];

    $this->co->post('finance', 'finance.revenue.store', $refund, $payment)->assertSessionHas('error');
    $this->co->post('finance', 'finance.revenue.store', [...$refund, 'financial_account_id' => $retired->id], $payment)->assertSessionHasErrors('financial_account_id');
    expect(RevenueAdjustment::count())->toBe(0);

    $this->co->post('finance', 'finance.revenue.store', [...$refund, 'financial_account_id' => $default->id], $payment)->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(RevenueAdjustment::firstOrFail()->financial_account_id)->toBe($default->id);
});
