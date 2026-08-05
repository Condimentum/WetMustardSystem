<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchCard extends Model
{
    protected $fillable = [
        'document_code',
        'title',
        'product_code',
        'revision',
        'issue_date',
        'effective_from',
        'effective_to',
        'status',
        'is_current',
        'pdf_path',
        'extracted_text',
        'metadata',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_current' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
