<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DBMTS Batch Ingredient Lot (scope entity: BatchIngredientLot).
 */
class BatchIngredientLot extends Model
{
    protected $fillable = [
        'batch_record_id',
        'recipe_ingredient_id',
        'material_code',
        'material_description',
        'required_quantity',
        'uom',
        'sequence',
        'lot_number',
        'supplier_lot_number',
        'actual_quantity',
        'weighed_by',
        'weighed_at',
        'tipped_by',
        'tipped_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'required_quantity' => 'decimal:3',
            'actual_quantity' => 'decimal:3',
            'weighed_at' => 'datetime',
            'tipped_at' => 'datetime',
        ];
    }

    public function batchRecord(): BelongsTo
    {
        return $this->belongsTo(BatchRecord::class);
    }

    public function weighedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'weighed_by');
    }

    public function tippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tipped_by');
    }

    public function issueLogs(): HasMany
    {
        return $this->hasMany(WinManIssueLog::class);
    }
}
