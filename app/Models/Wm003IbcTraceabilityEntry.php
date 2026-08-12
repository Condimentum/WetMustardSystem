<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wm003IbcTraceabilityEntry extends Model
{
    protected $fillable = [
        'date_used',
        'supplier_production_date',
        'best_before_date',
        'batch_no',
        'time_on',
        'operator_name',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_used' => 'date',
            'supplier_production_date' => 'date',
            'best_before_date' => 'date',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
