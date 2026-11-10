<?php

namespace App\Services;

use App\Models\InvoiceItem;
use App\Models\InvoiceLineClassification;
use App\Models\User;
use App\Support\InvoiceCategories;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finance proposes the category of an invoice line; a DIFFERENT authorised user verifies it. This is reporting metadata only: it never edits an invoice, a line, a
 * segment, a payment or a journal entry, so it cannot change recognised accounting. Every step is its own audited row, and a classification is reversed by proposing
 * (and verifying) "unclassified", never by deleting history.
 */
class InvoiceClassificationService
{
    public static function propose(InvoiceItem $item, string $category, string $reason, User $user): InvoiceLineClassification
    {
        if (! InvoiceCategories::isValid($category)) {
            throw new RuntimeException('Choose one of the listed categories.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('Give a short reason for the classification.');
        }

        return DB::transaction(function () use ($item, $category, $reason, $user) {
            InvoiceItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (InvoiceLineClassification::where('invoice_item_id', $item->id)->where('status', 'proposed')->exists()) {
                throw new RuntimeException('This line already has a classification waiting for verification.');
            }
            $row = InvoiceLineClassification::create(['invoice_item_id' => $item->id, 'category' => $category, 'reason' => trim($reason), 'proposed_by' => $user->id]);
            Audit::log('invoice.line.classify.proposed', 'invoice item '.$item->id, $category.' — '.trim($reason));

            return $row;
        });
    }

    public static function decide(InvoiceLineClassification $proposal, bool $approve, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($proposal, $approve, $user, $note): void {
            $row = InvoiceLineClassification::lockForUpdate()->findOrFail($proposal->id);
            if ($row->status !== 'proposed') {
                throw new RuntimeException('This classification was already decided.');
            }
            if ($row->proposed_by === $user->id) {
                throw new RuntimeException('You cannot verify a classification you proposed.');
            }
            $row->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr(trim($note), 0, 255) : null]);
            Audit::log('invoice.line.classify.'.($approve ? 'approved' : 'rejected'), 'invoice item '.$row->invoice_item_id, $row->category);
        });
    }
}
