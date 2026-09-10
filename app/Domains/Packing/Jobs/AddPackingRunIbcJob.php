<?php

namespace App\Domains\Packing\Jobs;

use App\Models\PackingRun;
use App\Models\PackingRunIbc;

/**
 * Records consumption of a pallecon/IBC (bulk source) by a packing run.
 */
class AddPackingRunIbcJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(PackingRun $run, array $attributes): PackingRunIbc
    {
        return $run->ibcs()->create([
            'pallecon_record_id' => $attributes['pallecon_record_id'] ?? null,
            'pallecon_id' => $attributes['pallecon_id'] ?? null,
            'source_batch_number' => $attributes['source_batch_number'] ?? null,
            'source_mo_number' => $attributes['source_mo_number'] ?? null,
            'time_on' => $attributes['time_on'] ?? null,
            'time_off' => $attributes['time_off'] ?? null,
        ]);
    }
}
