<?php

namespace App\Domains\Pallecon\Jobs;

use App\Domains\Pallecon\Exceptions\PalleconException;
use App\Domains\Pallecon\Support\PalleconCapacity;
use App\Models\BatchRecord;
use App\Models\Pallecon;
use App\Models\PalleconFill;
use App\Models\User;

/**
 * Attaches a batch contribution (fill) to an open pallecon container.
 *
 * Enforces the capacity/overfill limit against the running total of fills and
 * moves the container to "filling". Weights are always operator-entered; nothing
 * is derived from batch planned quantity.
 */
class AttachBatchFillJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(
        Pallecon $pallecon,
        BatchRecord $batch,
        array $attributes = [],
        ?User $user = null,
    ): PalleconFill {
        if (! $pallecon->isOpenForFilling()) {
            throw new PalleconException(
                "Pallecon is {$pallecon->status} and cannot receive further fills."
            );
        }

        $fillWeight = $this->normaliseWeight($attributes['fill_weight'] ?? null);

        if ($fillWeight !== null) {
            $projectedTotal = $pallecon->filledWeight() + $fillWeight;

            if (PalleconCapacity::exceedsLimit($projectedTotal)) {
                throw new PalleconException(sprintf(
                    'Fill would take the pallecon to %.3f kg, above the %.3f kg limit (%.0f kg + %.0f%% overfill).',
                    $projectedTotal,
                    PalleconCapacity::limitKg(),
                    PalleconCapacity::capacityKg(),
                    PalleconCapacity::overfillTolerance() * 100,
                ));
            }
        }

        $fill = $pallecon->fills()->create([
            'batch_record_id' => $batch->id,
            'fill_weight' => $fillWeight,
            'sequence' => (int) $pallecon->fills()->max('sequence') + 1,
            'filled_at' => now(),
            'signed_by' => $user?->id,
            'notes' => $attributes['notes'] ?? null,
        ]);

        if ($pallecon->status === Pallecon::STATUS_OPEN) {
            $pallecon->update(['status' => Pallecon::STATUS_FILLING]);
        }

        return $fill;
    }

    private function normaliseWeight(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
