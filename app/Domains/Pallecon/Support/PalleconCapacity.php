<?php

namespace App\Domains\Pallecon\Support;

use App\Models\Pallecon;

/**
 * Resolves pallecon capacity limits.
 *
 * capacity_kg (config) is only the physical container ceiling, checked when a
 * pallecon is opened. Once opened, the hard cap for fills and the final
 * recorded weight is that pallecon's own target_weight_kg - zero tolerance
 * above it. A pallecon with no target_weight_kg is unbounded.
 */
class PalleconCapacity
{
    public static function capacityKg(): float
    {
        return (float) config('dbmts.pallecon.capacity_kg', 1100);
    }

    /** Only meaningful at creation time: a target above the physical container size. */
    public static function exceedsPhysicalCapacity(float $targetWeightKg): bool
    {
        return $targetWeightKg > self::capacityKg() + 0.0001;
    }

    /** Remaining room in $pallecon before its own target, or null when unbounded (no target set). */
    public static function remainingKg(Pallecon $pallecon): ?float
    {
        if ($pallecon->target_weight_kg === null) {
            return null;
        }

        return max((float) $pallecon->target_weight_kg - $pallecon->filledWeight(), 0.0);
    }

    /** Whether $projectedTotalKg would exceed $pallecon's own target. False when unbounded. */
    public static function exceedsPalleconTarget(Pallecon $pallecon, float $projectedTotalKg): bool
    {
        if ($pallecon->target_weight_kg === null) {
            return false;
        }

        return $projectedTotalKg > (float) $pallecon->target_weight_kg + 0.0001;
    }
}
