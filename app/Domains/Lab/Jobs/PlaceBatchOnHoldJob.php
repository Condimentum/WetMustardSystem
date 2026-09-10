<?php

namespace App\Domains\Lab\Jobs;

use App\Models\BatchRecord;
use App\Models\Pallecon;
use Illuminate\Support\Collection;

/**
 * Places a batch on hold and propagates the hold to every pallecon the batch
 * contributed to (a shared container is quarantined even though other batches
 * in it may have passed - their product is physically commingled).
 *
 * Hold is orthogonal to lifecycle status, so a sealed or already-consumed
 * container is still flagged for downstream review.
 *
 * @return Collection<int, Pallecon> the pallecons newly placed on hold
 */
class PlaceBatchOnHoldJob
{
    public function __invoke(BatchRecord $batch, string $reason): Collection
    {
        $batch->forceFill(['held_at' => now(), 'hold_reason' => $reason])->save();

        $palleconIds = $batch->palleconFills()->pluck('pallecon_id')->unique();

        $pallecons = Pallecon::query()
            ->whereIn('id', $palleconIds)
            ->whereNull('held_at')
            ->get();

        foreach ($pallecons as $pallecon) {
            $pallecon->forceFill([
                'held_at' => now(),
                'hold_reason' => "Contains held batch {$batch->batch_number}: {$reason}",
            ])->save();
        }

        return $pallecons;
    }
}
