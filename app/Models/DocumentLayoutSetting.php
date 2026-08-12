<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentLayoutSetting extends Model
{
    protected $fillable = [
        'document_reference_id',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function documentReference(): BelongsTo
    {
        return $this->belongsTo(DocumentReference::class);
    }
}
