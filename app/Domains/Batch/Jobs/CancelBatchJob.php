<?php

namespace App\Domains\Batch\Jobs;

use App\Models\BatchRecord;
use App\Models\User;

/**
 * Cancels an in-progress batch so it can no longer be edited.
 */
class CancelBatchJob
{
    public function __invoke(BatchRecord $batch, ?User $user = null): BatchRecord
    {
        $batch->update([
            'status' => BatchRecord::STATUS_CANCELLED,
            'completed_by' => $user?->id,
            'completed_at' => now(),
        ]);

        return $batch->fresh();
    }
}
