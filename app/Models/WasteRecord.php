<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DBMTS Waste / Scrap record (scope §3).
 */
class WasteRecord extends Model
{
    public const CATEGORY_SPILLAGE = 'spillage';
    public const CATEGORY_DAMAGED_PACKAGING = 'damaged_packaging';
    public const CATEGORY_DISPOSAL = 'disposal';
    public const CATEGORY_PROCESS_LOSS = 'process_loss';
    public const CATEGORY_QA_REJECTION = 'qa_rejection';

    /** @var array<string, string> label map for UI. */
    public const CATEGORIES = [
        self::CATEGORY_SPILLAGE => 'Spillage',
        self::CATEGORY_DAMAGED_PACKAGING => 'Damaged Packaging',
        self::CATEGORY_DISPOSAL => 'Disposal',
        self::CATEGORY_PROCESS_LOSS => 'Process Loss',
        self::CATEGORY_QA_REJECTION => 'QA Rejection',
    ];

    protected $fillable = [
        'batch_record_id',
        'material_code',
        'material_description',
        'lot_number',
        'quantity',
        'uom',
        'category',
        'reason',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'recorded_at' => 'datetime',
        ];
    }

    public function batchRecord(): BelongsTo
    {
        return $this->belongsTo(BatchRecord::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
