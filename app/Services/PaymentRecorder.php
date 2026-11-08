<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\Project;
use App\Models\Segment;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates and updates payments so that each transaction is stored once and every
 * dependent total (segment received, invoice paid/status) stays consistent.
 */
class PaymentRecorder
{
    /**
     * Save a payment. Returns null when the idempotency key was already used (duplicate submit).
     *
     * @param  array{amount: int, paid_on: string, method: string, reference?: ?string, notes?: ?string, idempotency_key?: ?string}  $data
     * @param  array<int, int>|null  $allocations  segment_id => minor units; null means auto-allocate
     */
    public function save(Payment $payment, Project $project, ?Invoice $invoice, array $data, ?array $allocations, ?Segment $segment = null): ?Payment
    {
        if (! $payment->exists && ! empty($data['idempotency_key']) && Payment::where('idempotency_key', $data['idempotency_key'])->exists()) {
            return null;
        }

        try {
            return DB::transaction(function () use ($payment, $project, $invoice, $data, $allocations, $segment): Payment {
                $amount = $data['amount'];
                $exceptId = $payment->exists ? $payment->id : null;

                // A payment with a void awaiting a decision is frozen, so what the Board approves is what was proposed.
                if ($exceptId && PaymentVoidRequest::where('payment_id', $exceptId)->where('status', 'pending')->exists()) {
                    throw ValidationException::withMessages(['amount' => 'A void is pending for this payment, so it cannot be edited. Ask the Board to reject the void first.']);
                }

                if ($amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
                }

                if ($invoice) {
                    $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                    $alreadyPaid = (int) $invoice->payments()->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->sum('amount');
                    $remaining = $invoice->total - $alreadyPaid - $invoice->segmentPaymentsCredit();

                    if ($amount > $remaining) {
                        throw ValidationException::withMessages([
                            'amount' => 'Amount exceeds the invoice balance of '.Money::format(max(0, $remaining), $invoice->currency).'.',
                        ]);
                    }
                }

                if ($segment) {
                    $allocations = [$segment->id => $amount];
                } elseif ($allocations === null) {
                    $allocations = $invoice ? $this->autoAllocate($invoice, $amount, $exceptId) : [];
                }

                $this->validateAllocations($project, $allocations, $amount, $exceptId);

                // A NEW payment, or an edit that changes the account, must name an active account. An edit that leaves the account as it was is
                // never judged by today's accounts: the history it is already booked to is kept, whatever has happened to that account since.
                $requested = array_key_exists('financial_account_id', $data) ? ($data['financial_account_id'] ?: null) : ($payment->exists ? $payment->financial_account_id : null);
                if (! $payment->exists || (int) $requested !== (int) $payment->financial_account_id) {
                    $this->assertPaymentAccount($project, $data['paid_on'], $requested !== null ? (int) $requested : null);
                }

                $payment->fill([
                    'amount' => $amount,
                    'paid_on' => $data['paid_on'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);
                if (array_key_exists('financial_account_id', $data)) {
                    $payment->financial_account_id = $data['financial_account_id'] ?: null;
                }
                $payment->project()->associate($project);
                $payment->invoice()->associate($invoice);

                if (! $payment->exists) {
                    $payment->idempotency_key = ($data['idempotency_key'] ?? null) ?: (string) str()->uuid();
                }

                $payment->save();

                $payment->allocations()->delete();
                foreach (array_filter($allocations) as $segmentId => $allocated) {
                    $payment->allocations()->create(['segment_id' => $segmentId, 'amount' => $allocated]);
                }

                $invoice?->refreshPaymentStatus();
                Invoice::refreshBillingSegments(array_keys(array_filter($allocations)), $invoice?->id);
                LedgerPostingService::syncPayment($payment, auth()->id());

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Where a customer payment's money lands. An account that is named must be active. When accounts are in use and the payment is journaled in
     * real time (so its cash line is attributed to an account), it must name one: the default account is never assumed. Payments dated before
     * the real-time cutover are recognised at period close without an account, so they are not asked for one.
     */
    private function assertPaymentAccount(Project $project, string $paidOn, ?int $accountId): void
    {
        try {
            AccountingService::assertActive($accountId);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['financial_account_id' => $e->getMessage()]);
        }
        if ($accountId === null && FinancialAccount::query()->exists() && $project->currency === config('finance.currency')
            && LedgerPostingService::isRealtime(Carbon::parse($paidOn)->toDateString())) {
            throw ValidationException::withMessages(['financial_account_id' => 'Choose the account the money was received in.']);
        }
    }

    /**
     * Spread a payment over the segments billed on the invoice, never beyond what the invoice
     * billed for a segment or what the segment still owes.
     *
     * @return array<int, int>
     */
    private function autoAllocate(Invoice $invoice, int $amount, ?int $exceptPaymentId): array
    {
        $allocations = [];
        $left = $amount;

        $billedBySegment = $invoice->items()->whereNotNull('segment_id')->get()
            ->groupBy('segment_id')->map(fn ($items) => (int) $items->sum('amount'));

        foreach ($billedBySegment as $segmentId => $billed) {
            if ($left <= 0) {
                break;
            }

            $segment = Segment::find($segmentId);

            if (! $segment) {
                continue;
            }

            $allocatedFromInvoice = (int) DB::table('payment_allocations')
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->where('payments.invoice_id', $invoice->id)
                ->whereNull('payments.voided_at')
                ->where('payment_allocations.segment_id', $segmentId)
                ->when($exceptPaymentId, fn ($q) => $q->where('payments.id', '!=', $exceptPaymentId))
                ->sum('payment_allocations.amount');

            $cap = min($billed - $allocatedFromInvoice, $segment->balance($exceptPaymentId));
            $share = max(0, min($left, $cap));

            if ($share > 0) {
                $allocations[$segmentId] = $share;
                $left -= $share;
            }
        }

        return $allocations;
    }

    /**
     * @param  array<int, int>  $allocations
     */
    private function validateAllocations(Project $project, array $allocations, int $amount, ?int $exceptPaymentId): void
    {
        if (array_sum($allocations) > $amount) {
            throw ValidationException::withMessages(['allocations' => 'Segment allocations cannot exceed the payment amount.']);
        }

        foreach (array_filter($allocations) as $segmentId => $allocated) {
            $segment = $project->segments()->find($segmentId);

            if (! $segment) {
                throw ValidationException::withMessages(['allocations' => 'Allocation refers to a segment outside this project.']);
            }

            if ($allocated > $segment->balance($exceptPaymentId)) {
                throw ValidationException::withMessages([
                    'allocations' => "Allocation for \"{$segment->name}\" exceeds its remaining balance of ".Money::format(max(0, $segment->balance($exceptPaymentId)), $project->currency).'.',
                ]);
            }
        }
    }
}
