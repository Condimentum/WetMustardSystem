<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DBMTS Label Print Log: one attempted BarTender print for a pallecon label,
 * capturing what was sent (label_data) and whether it succeeded.
 */
class LabelPrintLog extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pallecon_id',
        'batch_record_id',
        'printed_by',
        'label_type',
        'serial_number',
        'fill_weight',
        'production_date',
        'status',
        'error_message',
        'label_data',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'fill_weight' => 'decimal:3',
            'production_date' => 'date',
            'label_data' => 'array',
            'printed_at' => 'datetime',
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

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
