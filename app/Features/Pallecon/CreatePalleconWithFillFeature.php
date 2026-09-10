<?php

namespace App\Features\Pallecon;

use App\Domains\Pallecon\Exceptions\PalleconException;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\User;

/**
 * Opens a pallecon and records its first batch fill in one step, enforcing the
 * "one open pallecon per MO at a time" rule.
 */
class CreatePalleconWithFillFeature
{
    public function __construct(
        private readonly OpenPalleconFeature $openPallecon,
        private readonly AttachBatchFillFeature $attachBatchFill,
    ) {
    }

    /** @return array{pallecon: Pallecon, fill: \App\Models\PalleconFill} */
    public function __invoke(
        ManufacturingOrder $order,
        string $serialNumber,
        BatchRecord $batch,
        float $fillWeight,
        User $user,
    ): array {
        if ($this->hasOpenContainerForMo((int) $order->id)) {
            throw new PalleconException('A pallecon is already open for this MO. Complete it before starting another.');
        }

        $pallecon = ($this->openPallecon)([
            'serial_number' => $serialNumber,
            'mo_number' => $order->mo_number,
        ], $user);

        $fill = ($this->attachBatchFill)($pallecon, $batch, ['fill_weight' => $fillWeight], $user);

        return ['pallecon' => $pallecon->fresh() ?? $pallecon, 'fill' => $fill];
    }

    private function hasOpenContainerForMo(int $moId): bool
    {
        return Pallecon::query()
            ->whereIn('status', [Pallecon::STATUS_OPEN, Pallecon::STATUS_FILLING])
            ->whereHas('fills.batchRecord', fn ($query) => $query->where('manufacturing_order_id', $moId))
            ->exists();
    }
}
