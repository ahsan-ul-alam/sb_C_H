<?php

namespace App\Services;

use App\Models\AdvanceSettlement;
use App\Models\Document;
use App\Models\InventoryEngineEnrolment;
use App\Models\LotOwnershipRequest;
use App\Models\ReconciliationException;
use App\Models\SourcingLot;
use Illuminate\Support\Collection;

/**
 * What a person needs to see before asking for a lot to be valued as Sellbees-owned stock: the product and accepted quantities, the project, who owns it, how much of
 * the supplier has been paid and settled, and why it can or cannot be enrolled. It only reads. The rules themselves live in InventoryEngine; the extra blockers here keep
 * a lot whose ownership is genuinely unknown out of the screen until its ownership evidence is on record, so enrolment can never settle ownership by the back door.
 */
class EnrolmentReadiness
{
    /** @return Collection<int, array<string, mixed>> */
    public static function lots(): Collection
    {
        return SourcingLot::where('status', '!=', 'cancelled')->with(['lines.product', 'project.client', 'allocations.project', 'payments'])->orderBy('id')->get()->map(fn (SourcingLot $lot) => self::for($lot));
    }

    /** @return array<string, mixed> */
    public static function for(SourcingLot $lot): array
    {
        $lot->loadMissing(['lines', 'project.client', 'allocations.project', 'payments']);
        $enrolment = InventoryEngineEnrolment::where('sourcing_lot_id', $lot->id)->first();
        $documents = Document::where('subject_type', 'sourcing_lot')->where('subject_id', $lot->id)->count();
        $purchase = (int) $lot->lines->sum(fn ($l) => $l->purchaseCost());
        $paid = SourcingPayments::paidTotal($lot->payments);
        $settled = (int) AdvanceSettlement::where('sourcing_lot_id', $lot->id)->where('status', 'verified')->sum('amount');
        $projects = collect([$lot->project?->name])->merge($lot->allocations->pluck('project.name'))->filter()->unique()->values()->all();

        $blockers = [];
        $warnings = [];
        if ($enrolment && $enrolment->status !== 'rejected') {
            $blockers[] = 'Already enrolled ('.$enrolment->status.').';
        } elseif ($lot->ownership === 'client') {
            $blockers[] = 'Client-owned stock is custody only and is not valued as a Sellbees asset.';
        } else {
            array_push($blockers, ...InventoryEngine::ineligibility($lot, 'company'));
            if ($lot->ownership === null) {
                if (ReconciliationException::where('key', 'lot:ownership:'.$lot->id)->where('status', 'open')->exists() || LotOwnershipRequest::where('sourcing_lot_id', $lot->id)->where('status', 'pending')->exists()) {
                    $blockers[] = 'Ownership is an open historical item: settle it in Finance → Historical Reconciliation first.';
                } elseif ($documents === 0) {
                    $blockers[] = 'Ownership is not stated and no supporting document is on the lot: upload the signed order or purchase order (Receiving & documents) first.';
                } else {
                    $warnings[] = 'Ownership is not stated yet: the Board\'s approval will classify it as Sellbees-owned on the evidence you cite.';
                }
            }
        }
        if ($lot->lines->isEmpty() || $lot->lines->every(fn ($l) => $l->received_quantity === null)) {
            $warnings[] = 'No arrival is recorded yet: stock and value appear only as goods are received and accepted.';
        }
        if ($paid < $purchase) {
            $warnings[] = 'The supplier is paid '.number_format($paid / 100, 2).' of '.number_format($purchase / 100, 2).'; accepted goods stay "pending valuation" until they are settled.';
        }

        return ['lot' => $lot, 'enrolment' => $enrolment, 'projects' => $projects, 'documents' => $documents, 'purchase' => $purchase, 'paid' => $paid, 'settled' => $settled,
            'lines' => $lot->lines->map(fn ($l) => ['description' => $l->description, 'unit' => $l->unit, 'ordered' => (string) $l->quantity, 'accepted' => $l->received_quantity === null ? null : (string) $l->usable_quantity]),
            'blockers' => $blockers, 'warnings' => $warnings, 'ready' => $blockers === []];
    }
}
