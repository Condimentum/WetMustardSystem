<?php

namespace App\Domains\Pallecon\Jobs;

use App\Domains\Pallecon\Exceptions\PalleconException;
use App\Domains\Pallecon\Support\PalleconCapacity;
use App\Models\Pallecon;

/**
 * Opens a new pallecon container ready to receive batch fills.
 *
 * A serial number may be reused over time (physical containers are reused), but
 * only one container per serial may be active (open/filling/sealed/on_hold) at
 * once - enforced here rather than by a database unique constraint.
 */
class OpenPalleconJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes = []): Pallecon
    {
        $serial = isset($attributes['serial_number']) ? trim((string) $attributes['serial_number']) : '';

        if ($serial !== '' && $this->activeSerialExists($serial)) {
            throw new PalleconException(
                "Pallecon serial \"{$serial}\" is already active. Seal or consume it before reopening the serial."
            );
        }

        return Pallecon::create([
            'manufacturing_order_id' => $attributes['manufacturing_order_id'] ?? null,
            'serial_number' => $serial !== '' ? $serial : null,
            'status' => Pallecon::STATUS_OPEN,
            'mo_number' => $attributes['mo_number'] ?? null,
            'capacity_kg' => PalleconCapacity::capacityKg(),
            'target_weight_kg' => $attributes['target_weight_kg'] ?? null,
            'production_date' => $attributes['production_date'] ?? null,
            'top_seal_number' => $attributes['top_seal_number'] ?? null,
            'bottom_seal_number' => $attributes['bottom_seal_number'] ?? null,
            'liner_number' => $attributes['liner_number'] ?? null,
            'liner_batch_code' => $attributes['liner_batch_code'] ?? null,
            'opened_at' => now(),
        ]);
    }

    private function activeSerialExists(string $serial): bool
    {
        return Pallecon::query()
            ->where('serial_number', $serial)
            ->whereIn('status', Pallecon::ACTIVE_STATUSES)
            ->exists();
    }
}
