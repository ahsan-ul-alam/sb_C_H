<?php

namespace App\Http\Controllers;

use App\Models\CapitalEntry;
use App\Models\OpeningBatch;
use App\Models\Partner;
use App\Services\Audit;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Opening balances come only from verified business records typed in by Finance, approved by a different Board
 * member, and posted once. The balancing figure goes to equity.opening. Later corrections are separate
 * "adjustment" batches that follow the same approval path.
 */
class OpeningController extends Controller
{
    /** kind => [label, account, side (debit|credit), needs partner] */
    public const KINDS = [
        'cash' => ['Cash in hand', 'cash', 'debit', false],
        'bank' => ['Bank balance', 'cash', 'debit', false],
        'receivable' => ['Customer receivable', 'receivables', 'debit', false],
        'partner_capital' => ['Partner paid capital', 'partner.capital', 'credit', true],
        'partner_entitlement' => ['Partner approved profit entitlement', 'partner.payable', 'credit', true],
        'fund_salary' => ['Salary Fund balance', 'fund.salary', 'credit', false],
        'fund_office' => ['Office & Growth Fund balance', 'fund.office', 'credit', false],
        'fund_emergency' => ['Emergency Reserve balance', 'fund.emergency', 'credit', false],
        'liability' => ['Supplier / other liability', 'liabilities', 'credit', false],
    ];

    public function index(): View
    {
        return view('finance.opening', [
            'batches' => OpeningBatch::with('lines')->latest('id')->get(),
            'partners' => Partner::orderBy('sort')->get(),
            'openingPosted' => OpeningBatch::where('type', 'opening')->whereIn('status', ['draft', 'approved', 'posted'])->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:opening,adjustment'], 'as_of' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:200']]);

        return $this->attempt(function () use ($request, $data) {
            if ($data['type'] === 'opening' && OpeningBatch::where('type', 'opening')->whereIn('status', ['draft', 'approved', 'posted'])->exists()) {
                throw new RuntimeException('An opening balance already exists. Use an adjustment batch for corrections.');
            }
            if ($data['type'] === 'adjustment' && ! OpeningBatch::where('type', 'opening')->where('status', 'posted')->exists()) {
                throw new RuntimeException('Post the opening balance before making adjustments.');
            }
            $batch = OpeningBatch::create([...$data, 'prepared_by' => $request->user()->id]);
            Audit::log('opening.created', 'batch '.$batch->id);
        }, 'Draft batch created; add its lines.');
    }

    public function addLine(Request $request, OpeningBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(self::KINDS))],
            'partner_id' => ['nullable', 'exists:partners,id'],
            'amount' => ['required', 'regex:'.Money::AMOUNT_REGEX],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->attempt(function () use ($batch, $data) {
            if ($batch->status !== 'draft') {
                throw new RuntimeException('Only a draft batch can be edited.');
            }
            if (self::KINDS[$data['kind']][3] && empty($data['partner_id'])) {
                throw new RuntimeException('Select the partner for this line.');
            }
            if ($data['kind'] === 'partner_capital'
                && CapitalEntry::where('partner_id', $data['partner_id'])->where('status', '!=', 'rejected')->whereDate('paid_on', '<=', $batch->as_of)->exists()) {
                throw new RuntimeException('This partner already has capital entries dated on or before the opening date. Use those entries, or reject them, instead of counting the capital twice.');
            }
            $amount = Money::toMinor($data['amount']);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be positive.');
            }
            $batch->lines()->create([...$data, 'partner_id' => self::KINDS[$data['kind']][3] ? $data['partner_id'] : null, 'amount' => $amount]);
        }, 'Line added.');
    }

    public function approve(Request $request, OpeningBatch $batch): RedirectResponse
    {
        return $this->attempt(function () use ($request, $batch) {
            if ($batch->status !== 'draft' || $batch->lines()->doesntExist()) {
                throw new RuntimeException('Only a draft batch with lines can be approved.');
            }
            if ($batch->prepared_by === $request->user()->id) {
                throw new RuntimeException('You cannot approve a batch you prepared.');
            }
            $batch->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            Audit::log('opening.approved', 'batch '.$batch->id);
        }, 'Batch approved.');
    }

    public function post(Request $request, OpeningBatch $batch): RedirectResponse
    {
        return $this->attempt(function () use ($request, $batch) {
            DB::transaction(function () use ($request, $batch) {
                $batch = OpeningBatch::lockForUpdate()->findOrFail($batch->id);
                if ($batch->status !== 'approved') {
                    throw new RuntimeException('Only an approved batch can be posted.');
                }
                $lines = [];
                $net = 0; // debits minus credits of the real lines
                foreach ($batch->lines as $l) {
                    [, $account, $side] = self::KINDS[$l->kind];
                    $lines[] = [$account, $l->partner_id, $side === 'debit' ? (int) $l->amount : 0, $side === 'credit' ? (int) $l->amount : 0];
                    $net += $side === 'debit' ? (int) $l->amount : -(int) $l->amount;
                }
                if ($net !== 0) {
                    $lines[] = ['equity.opening', null, $net < 0 ? -$net : 0, $net > 0 ? $net : 0];
                }
                $entry = Ledger::post($batch->as_of->toDateString(), ucfirst($batch->type).' balances', 'opening', $batch->id, $lines, $request->user()->id);
                $batch->update(['status' => 'posted', 'posted_by' => $request->user()->id, 'posted_at' => now(), 'journal_entry_id' => $entry->id]);
                Audit::log('opening.posted', 'batch '.$batch->id);
            });
        }, 'Opening balances posted to the ledger.');
    }

    public function cancel(OpeningBatch $batch): RedirectResponse
    {
        return $this->attempt(function () use ($batch) {
            if (! in_array($batch->status, ['draft', 'approved'], true)) {
                throw new RuntimeException('A posted batch cannot be cancelled; use an adjustment batch.');
            }
            $batch->update(['status' => 'cancelled']);
            Audit::log('opening.cancelled', 'batch '.$batch->id);
        }, 'Batch cancelled.');
    }
}
