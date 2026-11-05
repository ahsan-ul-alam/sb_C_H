<?php

namespace App\Services;

use App\Models\Document;
use App\Models\LotOwnershipRequest;
use App\Models\SourcingLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ownership of a lot decides how its sales are accounted and cannot be changed once set, so for a lot whose ownership is unknown it is set only through a request with
 * evidence and a second person's approval. Approving sets the ownership (and the client project) and nothing else: no valuation, no COGS and no journal entry.
 * While ownership is unknown, everything that depends on it (handover, valuation, supplier returns, client settlement) stays blocked by the existing rules.
 */
class OwnershipRequests
{
    /** @return list<array{label: string, met: bool, detail: string}> the evidence that is on record for the lot */
    public static function evidence(SourcingLot $lot): array
    {
        $lot->loadMissing(['lines', 'payments', 'allocations']);
        $documents = Document::where('subject_type', 'sourcing_lot')->where('subject_id', $lot->id)->count();

        return [
            ['label' => 'Client project assigned to the lot', 'met' => $lot->project_id !== null, 'detail' => $lot->project_id ? 'project #'.$lot->project_id : 'none'],
            ['label' => 'Purchase order number', 'met' => trim((string) $lot->purchase_order) !== '', 'detail' => $lot->purchase_order ?: 'none'],
            ['label' => 'Supplier payment recorded', 'met' => $lot->payments->isNotEmpty(), 'detail' => $lot->payments->count().' payment(s)'],
            ['label' => 'Allocation to a client project', 'met' => $lot->allocations->isNotEmpty(), 'detail' => $lot->allocations->count().' allocation(s)'],
            ['label' => 'Arrival recorded', 'met' => $lot->lines->contains(fn ($l) => $l->received_quantity !== null), 'detail' => $lot->lines->whereNotNull('received_quantity')->count().' line(s) received'],
            ['label' => 'Supporting documents uploaded (signed order, supplier invoice, agreement)', 'met' => $documents > 0, 'detail' => $documents.' document(s)'],
        ];
    }

    public static function propose(SourcingLot $lot, string $ownership, ?int $projectId, string $evidenceNote, User $user): LotOwnershipRequest
    {
        if ($lot->ownership !== null) {
            throw new RuntimeException('Ownership of this lot is already recorded and cannot be changed.');
        }
        if (! in_array($ownership, ['company', 'client'], true)) {
            throw new RuntimeException('Choose Sellbees-owned or client-owned.');
        }
        if ($ownership === 'client' && ! $projectId && ! $lot->project_id) {
            throw new RuntimeException('A client-owned lot needs the client project it is for.');
        }
        if (mb_strlen(trim($evidenceNote)) < 20) {
            throw new RuntimeException('Describe the evidence (which documents, who signed, what they say) in at least a sentence.');
        }
        if (LotOwnershipRequest::where('sourcing_lot_id', $lot->id)->where('status', 'pending')->exists()) {
            throw new RuntimeException('A request for this lot is already awaiting approval.');
        }
        $request = LotOwnershipRequest::create(['sourcing_lot_id' => $lot->id, 'proposed_ownership' => $ownership, 'project_id' => $projectId, 'evidence_note' => trim($evidenceNote), 'proposed_by' => $user->id]);
        Audit::log('sourcing.ownership_requested', 'lot '.$lot->number, $ownership);

        return $request;
    }

    public static function decide(LotOwnershipRequest $request, bool $approve, ?string $note, User $user): void
    {
        DB::transaction(function () use ($request, $approve, $note, $user) {
            $r = LotOwnershipRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            $lot = SourcingLot::whereKey($r->sourcing_lot_id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                throw new RuntimeException('This request is already '.$r->status.'.');
            }
            if ($r->proposed_by === $user->id) {
                throw new RuntimeException('The decision must be made by someone other than the person who proposed it.');
            }
            if (! $approve) {
                if (trim((string) $note) === '') {
                    throw new RuntimeException('Give the reason for rejecting it.');
                }
                $r->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => trim($note)]);
                Audit::log('sourcing.ownership_rejected', 'lot '.$lot->number);

                return;
            }
            if ($lot->ownership !== null) {
                throw new RuntimeException('Ownership was recorded in the meantime; nothing was changed.');
            }
            if (! Document::where('subject_type', 'sourcing_lot')->where('subject_id', $lot->id)->exists()) {
                throw new RuntimeException('Upload the supporting documents to the lot (Receiving & documents) before ownership can be approved.');
            }
            $lot->update(['ownership' => $r->proposed_ownership] + ($r->project_id && ! $lot->project_id ? ['project_id' => $r->project_id] : []));
            $r->update(['status' => 'approved', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note ? trim($note) : null]);
            Audit::log('sourcing.ownership', 'lot '.$lot->number, $r->proposed_ownership.' (approved request #'.$r->id.'; no valuation or journal entry)');
        });
    }
}
