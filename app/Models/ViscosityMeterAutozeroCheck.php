<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViscosityMeterAutozeroCheck extends Model
{
    protected $fillable = [
        'checked_date',
        'complete',
        'operator_name',
        'deviation_reason',
        'checked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'checked_date' => 'date',
            'complete' => 'boolean',
        ];
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }
}
