<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * DBMTS Manufacturing Batch Record (scope entity: BatchRecord).
 *
 * The electronic batchcard header for a production run against a selected MO.
 */
class BatchRecord extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_QA_REVIEW = 'qa_review';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'manufacturing_order_id',
        'product_id',
        'variant_id',
        'batch_number',
        'production_date',
        'shift',
        'planned_quantity',
        'status',
        'created_by',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'planned_quantity' => 'decimal:3',
            'completed_at' => 'datetime',
        ];
    }

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(RecipeVariant::class, 'variant_id');
    }

    public function ingredientLots(): HasMany
    {
        return $this->hasMany(BatchIngredientLot::class);
    }

    public function processSteps(): HasMany
    {
        return $this->hasMany(BatchProcessStep::class);
    }

    public function processParameters(): HasMany
    {
        return $this->hasMany(BatchProcessParameter::class);
    }

    public function metalDetectorChecks(): HasMany
    {
        return $this->hasMany(MetalDetectorCheck::class);
    }

    public function pallecons(): HasMany
    {
        return $this->hasMany(PalleconRecord::class);
    }

    public function packingRuns(): HasMany
    {
        return $this->hasMany(PackingRun::class);
    }

    public function drumProcessingRuns(): HasMany
    {
        return $this->hasMany(DrumProcessingRun::class);
    }

    public function packagingLots(): HasMany
    {
        return $this->hasMany(PackagingLot::class);
    }

    public function bookingLogs(): HasMany
    {
        return $this->hasMany(WinManBookingLog::class);
    }

    public function issueLogs(): HasMany
    {
        return $this->hasMany(WinManIssueLog::class);
    }

    public function componentSnapshots(): HasManyThrough
    {
        return $this->hasManyThrough(
            WinManMoComponentSnapshot::class,
            ManufacturingOrder::class,
            'id',
            'manufacturing_order_id',
            'manufacturing_order_id',
            'id',
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
