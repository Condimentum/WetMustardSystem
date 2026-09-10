<?php

namespace App\Domains\Lab\Jobs;

use App\Models\BatchRecord;
use App\Models\Pallecon;

/**
 * Releases a batch hold and re-evaluates each pallecon the batch contributed to:
 * a container stays held while ANY other contributing batch is still on hold,
 * otherwise its hold is cleared.
 */
class ReleaseBatchHoldJob
{
    public function __invoke(BatchRecord $batch, string $reason): void
    {
        $batch->forceFill(['held_at' => null, 'hold_reason' => null])->save();

        $palleconIds = $batch->palleconFills()->pluck('pallecon_id')->unique();

        $pallecons = Pallecon::query()
            ->whereIn('id', $palleconIds)
            ->whereNotNull('held_at')
            ->with('fills.batchRecord:id,held_at')
            ->get();

        foreach ($pallecons as $pallecon) {
            $stillHeldByAnother = $pallecon->fills
                ->contains(fn ($fill) => $fill->batchRecord !== null
                    && $fill->batch_record_id !== $batch->id
                    && $fill->batchRecord->held_at !== null);

            if (! $stillHeldByAnother) {
                $pallecon->forceFill(['held_at' => null, 'hold_reason' => null])->save();
            }
        }
    }
}
