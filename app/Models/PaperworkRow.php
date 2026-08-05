<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaperworkRow extends Model
{
    protected $fillable = [
        'manufacturing_order_id',
        'manufacturing_order_ref',
        'batch_record_id',
        'batch_number',
        'batch_column_index',
        'product_id',
        'recipe_code',
        'recipe_revision',
        'row_key',
        'row_label',
        'row_order',
        'value_text',
        'value_number',
        'value_bool',
        'value_datetime',
        'unit',
        'status',
        'completed_at',
        'completed_by',
        'entered_by',
        'entered_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'batch_column_index' => 'integer',
            'row_order' => 'integer',
            'value_number' => 'decimal:5',
            'value_bool' => 'boolean',
            'value_datetime' => 'datetime',
            'completed_at' => 'datetime',
            'entered_at' => 'datetime',
        ];
    }

    public function manufacturingOrder(): BelongsTo
    {
        return $this->belongsTo(ManufacturingOrder::class);
    }

    public function batchRecord(): BelongsTo
    {
        return $this->belongsTo(BatchRecord::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
