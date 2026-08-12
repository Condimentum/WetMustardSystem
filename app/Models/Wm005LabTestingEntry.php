<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wm005LabTestingEntry extends Model
{
    protected $fillable = [
        'tested_date',
        'part_number',
        'product',
        'mo_number',
        'tested_time',
        'batch_number',
        'analytical_specification',
        'ph',
        'acidity_acetic',
        'acidity_citric',
        'salt',
        'viscosity_brookfield',
        'viscosity_bostwick',
        'aw',
        'solids',
        'appearance',
        'tested_by',
        'recorded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tested_date' => 'date',
            'ph' => 'decimal:3',
            'acidity_acetic' => 'decimal:3',
            'acidity_citric' => 'decimal:3',
            'salt' => 'decimal:3',
            'viscosity_brookfield' => 'decimal:3',
            'viscosity_bostwick' => 'decimal:3',
            'aw' => 'decimal:3',
            'solids' => 'decimal:3',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
