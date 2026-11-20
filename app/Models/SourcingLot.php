<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourcingLot extends Model
{
    public const STATUSES = [
        'draft' => 'Draft', 'costing' => 'Costing', 'priced' => 'Priced', 'approved' => 'Approved',
        'in_progress' => 'In progress', 'completed' => 'Completed', 'settled' => 'Financially settled', 'cancelled' => 'Cancelled',
    ];

    /** Forward steps of the lifecycle (approval and settlement have their own guarded actions). */
    public const FLOW = ['draft', 'costing', 'priced', 'approved', 'in_progress', 'completed', 'settled'];

    protected $guarded = [];

    /** Where the goods are (operational). Kept apart from the financial position, which is derived from the books. */
    public const PROCUREMENT = ['ordered' => 'Purchase order placed', 'preparing' => 'Supplier preparing', 'dispatched' => 'Dispatched', 'in_transit' => 'In transit', 'arrived' => 'Arrived at our room'];

    /**
     * The three operational stages shown to users, derived from the lifecycle status (never stored twice). Every status maps to exactly one
     * stage: before the order is placed (draft … approved) the lot is being processed; in_progress is the order placed and awaiting the goods;
     * completed and settled both mean the goods were received (settled additionally closes the books).
     */
    public const STAGES = ['processing' => 'Processing', 'in_order' => 'In order', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    /** @var array<string, list<string>> */
    public const STAGE_STATUSES = [
        'processing' => ['draft', 'costing', 'priced', 'approved'],
        'in_order' => ['in_progress'],
        'completed' => ['completed', 'settled'],
        'cancelled' => ['cancelled'],
    ];

    protected function casts(): array
    {
        return [
            'pricing_value' => 'integer', 'approved_cost' => 'integer', 'approved_revenue' => 'integer', 'client_budget' => 'integer',
            'approved_at' => 'datetime', 'settled_at' => 'datetime', 'cancelled_at' => 'datetime', 'storage_measured_on' => 'date',
            'ordered_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function stage(): string
    {
        foreach (self::STAGE_STATUSES as $stage => $statuses) {
            if (in_array($this->status, $statuses, true)) {
                return $stage;
            }
        }

        return 'processing';
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage()];
    }

    /** Made on the simple client-lot form: one product, priced by a budget agreed for the whole lot. Older lots are priced per line. */
    public function hasClientBudget(): bool
    {
        return $this->client_budget !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * @return HasMany<SourcingLotLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SourcingLotLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<SourcingLotCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(SourcingLotCost::class)->orderBy('incurred_on')->orderBy('id');
    }

    /**
     * @return HasMany<SourcingAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SourcingAllocation::class)->orderBy('id');
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->orderByDesc('paid_on')->orderByDesc('id');
    }

    public function ownershipRequests(): HasMany
    {
        return $this->hasMany(LotOwnershipRequest::class);
    }

    public function advanceSettlements(): HasMany
    {
        return $this->hasMany(AdvanceSettlement::class);
    }

    public function costRecognitions(): HasMany
    {
        return $this->hasMany(CostRecognition::class);
    }

    /**
     * @return HasMany<EmergencyFundRequest, $this>
     */
    public function fundRequests(): HasMany
    {
        return $this->hasMany(EmergencyFundRequest::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The client project this lot is for (decides whose stock it is once the lot is stated as client-owned). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['settled', 'cancelled'], true);
    }

    /** Lines (quantities, supplier prices) may change until pricing is approved. */
    public function linesEditable(): bool
    {
        return in_array($this->status, ['draft', 'costing', 'priced'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public static function nextNumber(): string
    {
        $last = (int) static::query()->max('id');

        return 'SL-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }
}
