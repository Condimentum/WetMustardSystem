<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document reference issue/change history entry.
 */
class DocumentReferenceChange extends Model
{
    protected $fillable = [
        'document_reference_id',
        'issue_version',
        'date_issued',
        'issued_by',
        'reason_for_change',
        'changed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_issued' => 'date',
        ];
    }

    public function documentReference(): BelongsTo
    {
        return $this->belongsTo(DocumentReference::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
