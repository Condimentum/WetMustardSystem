<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DBMTS Pallecon Fill: one batch's contribution to a pallecon container.
 *
 * The bridge that makes batch <-> pallecon a many-to-many relationship without
 * merging batches. Each fill records which batch added product, in what order,
 * an optional contribution weight and the operator signature.
 */
class PalleconFill extends Model
{
    protected $fillable = [
        'pallecon_id',
        'batch_record_id',
        'fill_weight',
        'sequence',
        'filled_at',
        'signed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'fill_weight' => 'decimal:3',
            'sequence' => 'integer',
            'filled_at' => 'datetime',
        ];
    }

    public function pallecon(): BelongsTo
    {
        return $this->belongsTo(Pallecon::class);
    }

    public function batchRecord(): BelongsTo
    {
        return $this->belongsTo(BatchRecord::class);
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
