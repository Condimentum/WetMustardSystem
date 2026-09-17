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
 * Enforces the pallecon's own target_weight_kg against the running total of
 * fills and moves the container to "filling". Weights are always
 * operator-entered; nothing is derived from batch planned quantity.
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

            if (PalleconCapacity::exceedsPalleconTarget($pallecon, $projectedTotal)) {
                throw new PalleconException(sprintf(
                    'Fill would take the pallecon to %.3f kg, above its %.3f kg target.',
                    $projectedTotal,
                    (float) $pallecon->target_weight_kg,
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
