<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wm010RinseWaterTestEntry extends Model
{
    public const SECTION_CLEANING_CHEMICALS = 'cleaning_chemicals';
    public const SECTION_SULPHITES = 'sulphites';
    public const SECTION_CHEMICAL_TITRATION = 'chemical_titration';

    protected $fillable = [
        'tested_date',
        'section',
        'equipment',
        'reading',
        'reading_unit',
        'pass_or_fail',
        'action_taken_if_failed',
        'operator_name',
        'chemical_used',
        'target',
        'result',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tested_date' => 'date',
            'reading' => 'decimal:3',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
