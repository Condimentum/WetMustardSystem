<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DBMTS Pallecon container (first-class, replaces one-batch-per-pallecon model).
 *
 * A physical bulk container filled from one or more batches via PalleconFill.
 * The final recorded weight is captured at scale-off and is authoritative for
 * labels. Container composition is DBMTS-only data and is never sent to WinMan.
 */
class Pallecon extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FILLING = 'filling';
    public const STATUS_SEALED = 'sealed';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_CONSUMED = 'consumed';

    /** Statuses that still occupy a physical container (block reuse of the serial). */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_FILLING,
        self::STATUS_SEALED,
        self::STATUS_ON_HOLD,
    ];

    protected $fillable = [
        'manufacturing_order_id',
        'serial_number',
        'status',
        'mo_number',
        'capacity_kg',
        'target_weight_kg',
        'production_date',
        'winman_reference',
        'final_weight',
        'top_seal_number',
        'bottom_seal_number',
        'liner_number',
        'liner_batch_code',
        'opened_at',
        'sealed_at',
        'sealed_by',
        'hold_reason',
        'held_at',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'decimal:3',
            'target_weight_kg' => 'decimal:3',
            'final_weight' => 'decimal:3',
            'production_date' => 'date',
            'opened_at' => 'datetime',
            'sealed_at' => 'datetime',
            'held_at' => 'datetime',
        ];
    }

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function fills(): HasMany
    {
        return $this->hasMany(PalleconFill::class);
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(BatchRecord::class, 'pallecon_fills')
            ->withPivot(['fill_weight', 'sequence', 'filled_at', 'signed_by'])
            ->withTimestamps();
    }

    public function sealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isOpenForFilling(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_FILLING], true);
    }

    public function isOnHold(): bool
    {
        return $this->held_at !== null;
    }

    /** Sum of recorded per-batch fill contributions. */
    public function filledWeight(): float
    {
        return (float) $this->fills()->sum('fill_weight');
    }
}
