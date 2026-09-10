<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DBMTS Packing Run IBC consumption (scope entity: PackingRunIBC).
 */
class PackingRunIbc extends Model
{
    protected $fillable = [
        'packing_run_id', 'pallecon_record_id', 'pallecon_id', 'source_batch_number', 'source_mo_number', 'time_on', 'time_off',
    ];

    protected function casts(): array
    {
        return ['time_on' => 'datetime', 'time_off' => 'datetime'];
    }

    public function packingRun(): BelongsTo
    {
        return $this->belongsTo(PackingRun::class);
    }

    public function palleconRecord(): BelongsTo
    {
        return $this->belongsTo(PalleconRecord::class);
    }

    public function pallecon(): BelongsTo
    {
        return $this->belongsTo(Pallecon::class);
    }
}
