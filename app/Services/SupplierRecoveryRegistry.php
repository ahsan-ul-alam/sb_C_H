<?php

namespace App\Services;

use App\Models\SupplierRecoveryDocument;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * One register of the documents behind supplier recoveries, shared by every workflow that can recognise or collect one:
 *   - a supplier payment's refund or credit-note correction (SourcingPayments),
 *   - a supplier return's acceptance document (credit note number or written acceptance),
 *   - a supplier return's refund settlement (the bank receipt),
 *   - a payment correction that a claim for rejected goods was recovered through.
 * The same document of the same supplier is active once. Registering the same document for the same source again is a no-op (idempotent); for another source it is
 * refused with the workflow that already holds it, so a credit note booked through a payment correction cannot be booked again as a return's credit, and one bank
 * receipt cannot be collected twice. Callers hold their own row locks; the unique index is the backstop when two requests race.
 */
class SupplierRecoveryRegistry
{
    public static function key(string $reference): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
    }

    public static function claim(int $supplierId, string $kind, string $reference, string $sourceType, int $sourceId, ?int $amount, ?int $userId): SupplierRecoveryDocument
    {
        $key = self::key($reference);
        if ($key === '') {
            throw new RuntimeException('Enter the document reference.');
        }
        $held = SupplierRecoveryDocument::where('supplier_id', $supplierId)->where('document_key', $key)->where('active', 1)->first();
        if ($held) {
            if ($held->source_type === $sourceType && (int) $held->source_id === $sourceId) {
                return $held;
            }
            throw new RuntimeException('The document "'.trim($reference).'" is already registered for this supplier ('.str_replace('_', ' ', $held->source_type).' #'.$held->source_id.'): a recovery is recognised and collected once, in one workflow.');
        }
        try {
            return SupplierRecoveryDocument::create(['supplier_id' => $supplierId, 'kind' => $kind, 'document_key' => $key, 'reference' => trim($reference), 'source_type' => $sourceType, 'source_id' => $sourceId,
                'amount' => $amount, 'created_by' => $userId, 'active' => 1]);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('The document "'.trim($reference).'" was registered a moment ago by another request; a recovery is recognised and collected once.');
        }
    }

    /** Free the documents of an event that was cancelled or rejected, so the same genuine document can be used for the event that really happened. */
    public static function release(string $sourceType, int $sourceId, ?array $kinds = null): void
    {
        SupplierRecoveryDocument::where('source_type', $sourceType)->where('source_id', $sourceId)->where('active', 1)->when($kinds, fn ($q) => $q->whereIn('kind', $kinds))->update(['active' => null, 'released_at' => now()]);
    }

    /** Free one document by its reference (a payment correction that was rejected no longer recovers the claim it was linked to). */
    public static function releaseReference(string $reference): void
    {
        SupplierRecoveryDocument::where('document_key', self::key($reference))->where('active', 1)->update(['active' => null, 'released_at' => now()]);
    }

    /** What has been registered for a source and kind (in force only). */
    public static function total(string $sourceType, int $sourceId, string $kind): int
    {
        return (int) SupplierRecoveryDocument::where('source_type', $sourceType)->where('source_id', $sourceId)->where('kind', $kind)->where('active', 1)->sum('amount');
    }
}
