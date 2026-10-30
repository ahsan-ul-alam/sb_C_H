<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Segment;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

                $payment->fill([
                    'amount' => $amount,
                    'paid_on' => $data['paid_on'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);
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

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $invoice = $payment->invoice;
            $segmentIds = $payment->allocations()->pluck('segment_id')->all();
            $payment->delete();
            $invoice?->refreshPaymentStatus();
            Invoice::refreshBillingSegments($segmentIds, $invoice?->id);
        });
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
