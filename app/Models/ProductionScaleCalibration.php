<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionScaleCalibration extends Model
{
    protected $fillable = [
        'checked_date',
        'powder_3kg_reading',
        'powder_30kg_reading',
        'pallecon_scale_reading',
        'bucket_filler_scale_reading',
        'passed',
        'operator_name',
        'deviation_reason',
        'checked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'checked_date' => 'date',
            'powder_3kg_reading' => 'decimal:3',
            'powder_30kg_reading' => 'decimal:3',
            'pallecon_scale_reading' => 'decimal:3',
            'bucket_filler_scale_reading' => 'decimal:3',
            'passed' => 'boolean',
        ];
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }
}
