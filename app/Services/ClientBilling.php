<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Segment;
use RuntimeException;

/**
 * The one place that puts a charge onto a client's EXISTING billing (segment + invoice item), reusing the invoice
 * payment-credit logic. Used by sourcing sales and order commission so there is no second invoice engine.
 * Call inside a DB transaction.
 */
class ClientBilling
{
    /**
     * @return array{0: Invoice, 1: Segment}
     */
    public static function addCharge(Project $project, string $segmentName, ?string $segmentNote, ?int $segmentId, ?int $invoiceId, string $purpose, string $description, string $quantity, ?string $unit, int $unitPrice, int $amount): array
    {
        $segment = $segmentId ? $project->segments()->lockForUpdate()->find($segmentId) : null;
        if ($segmentId && ! $segment) {
            throw new RuntimeException('That segment is not part of the client project.');
        }
        if ($segment) {
            $segment->update(['amount' => $segment->amount + $amount]);
        } else {
            $segment = $project->segments()->create(['name' => $segmentName, 'description' => $segmentNote, 'amount' => $amount]);
        }

        if ($invoiceId) {
            $invoice = $project->invoices()->lockForUpdate()->find($invoiceId);
            if (! $invoice || $invoice->status === InvoiceStatus::Paid) {
                throw new RuntimeException('Choose an open invoice of this project.');
            }
        } else {
            $invoice = $project->invoices()->make(['number' => Invoice::nextNumber(), 'issued_on' => today(), 'purpose' => $purpose]);
            $invoice->currency = $project->currency;
            $invoice->status = InvoiceStatus::Unpaid;
            $invoice->save();
        }
        $invoice->items()->create([
            'segment_id' => $segment->id, 'description' => $description, 'quantity' => $quantity, 'unit' => $unit,
            'unit_price' => $unitPrice, 'amount' => $amount, 'position' => (int) $invoice->items()->max('position') + 1,
        ]);
        $invoice->total += $amount;
        $invoice->refreshPaymentStatus();
        Invoice::refreshBillingSegments([$segment->id], $invoice->id);

        return [$invoice, $segment];
    }
}
