<?php

namespace App\Domains\Pallecon\Jobs;

use App\Domains\Pallecon\Exceptions\PalleconException;
use App\Domains\Pallecon\Support\PalleconCapacity;
use App\Models\Pallecon;
use App\Models\User;

/**
 * Seals a pallecon at scale-off: records the authoritative final weight (used for
 * the label) and optional seal numbers, then moves the container to "sealed".
 */
class SealPalleconJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(Pallecon $pallecon, array $attributes, ?User $user = null): Pallecon
    {
        if (! $pallecon->isOpenForFilling()) {
            throw new PalleconException(
                "Pallecon is {$pallecon->status} and cannot be sealed."
            );
        }

        if ($pallecon->fills()->count() === 0) {
            throw new PalleconException('Cannot seal a pallecon with no batch fills.');
        }

        $finalWeight = $attributes['final_weight'] ?? null;

        if ($finalWeight === null || $finalWeight === '' || ! is_numeric($finalWeight)) {
            throw new PalleconException('A final recorded weight is required to seal a pallecon.');
        }

        $finalWeight = (float) $finalWeight;

        if ($finalWeight <= 0) {
            throw new PalleconException('Final recorded weight must be greater than zero.');
        }

        if (PalleconCapacity::exceedsLimit($finalWeight)) {
            throw new PalleconException(sprintf(
                'Final weight %.3f kg is above the %.3f kg limit (%.0f kg + %.0f%% overfill).',
                $finalWeight,
                PalleconCapacity::limitKg(),
                PalleconCapacity::capacityKg(),
                PalleconCapacity::overfillTolerance() * 100,
            ));
        }

        $pallecon->update([
            'final_weight' => $finalWeight,
            'top_seal_number' => $attributes['top_seal_number'] ?? $pallecon->top_seal_number,
            'bottom_seal_number' => $attributes['bottom_seal_number'] ?? $pallecon->bottom_seal_number,
            'liner_number' => $attributes['liner_number'] ?? $pallecon->liner_number,
            'liner_batch_code' => $attributes['liner_batch_code'] ?? $pallecon->liner_batch_code,
            'status' => Pallecon::STATUS_SEALED,
            'sealed_at' => now(),
            'sealed_by' => $user?->id,
        ]);

        return $pallecon->refresh();
    }
}
