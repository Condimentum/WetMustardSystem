<?php

namespace App\Domains\Pallecon\Support;

/**
 * Resolves the effective pallecon fill limit from configuration.
 *
 * limit = capacity_kg * (1 + overfill_tolerance). Applies to both the running
 * total of batch fills and the final recorded weight. There is no minimum.
 */
class PalleconCapacity
{
    public static function capacityKg(): float
    {
        return (float) config('dbmts.pallecon.capacity_kg', 1100);
    }

    public static function overfillTolerance(): float
    {
        return (float) config('dbmts.pallecon.overfill_tolerance', 0.10);
    }

    public static function limitKg(): float
    {
        return round(self::capacityKg() * (1 + self::overfillTolerance()), 3);
    }

    public static function exceedsLimit(float $weightKg): bool
    {
        return $weightKg > self::limitKg() + 0.0001;
    }
}
