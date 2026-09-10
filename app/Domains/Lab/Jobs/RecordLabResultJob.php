<?php

namespace App\Domains\Lab\Jobs;

use App\Models\BatchLabResult;
use App\Models\BatchRecord;
use App\Models\User;

/**
 * Records a QA lab pass/fail decision against a production batch.
 */
class RecordLabResultJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(BatchRecord $batch, array $attributes, ?User $user = null): BatchLabResult
    {
        return $batch->labResults()->create([
            'result' => $attributes['result'],
            'analytical_spec' => $attributes['analytical_spec'] ?? null,
            'comment' => $attributes['comment'] ?? null,
            'tested_at' => $attributes['tested_at'] ?? now(),
            'tested_by' => $attributes['tested_by'] ?? $user?->name,
            'recorded_by' => $user?->id,
        ]);
    }
}
