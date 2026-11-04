<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A document that proves a supplier recovery (credit note, written acceptance, bank receipt, payment correction), registered once per supplier across every workflow.
 */
class SupplierRecoveryDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'released_at' => 'datetime'];
    }

    public function delete(): never
    {
        throw new \LogicException('A recovery document is part of the audit trail: it is released, never deleted.');
    }
}
