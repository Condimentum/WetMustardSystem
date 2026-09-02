<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * DBMTS Document Reference Master (scope entity: DocumentReference).
 *
 * Registry of controlled WM source documents (batchcards, check sheets, etc.).
 */
class DocumentReference extends Model
{
    protected $fillable = [
        'code',
        'title',
        'version',
        'issue_date',
        'module',
        'trigger_material_codes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'trigger_material_codes' => 'array',
        ];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(DocumentReferenceChange::class);
    }

    public function layoutSetting(): HasOne
    {
        return $this->hasOne(DocumentLayoutSetting::class);
    }
}
