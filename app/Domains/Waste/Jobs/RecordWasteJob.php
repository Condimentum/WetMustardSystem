<?php

namespace App\Domains\Waste\Jobs;

use App\Domains\Waste\Exceptions\WasteException;
use App\Models\WasteRecord;
use App\Models\User;

/**
 * Records a waste / scrap entry. An operator must supply a category, a reason
 * and a positive quantity (scope §3).
 */
class RecordWasteJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes, ?User $user = null): WasteRecord
    {
        $category = (string) ($attributes['category'] ?? '');

        if (! array_key_exists($category, WasteRecord::CATEGORIES)) {
            throw new WasteException('A valid waste category is required.');
        }

        $reason = trim((string) ($attributes['reason'] ?? ''));

        if ($reason === '') {
            throw new WasteException('A reason is required for every waste entry.');
        }

        $quantity = (float) ($attributes['quantity'] ?? 0);

        if ($quantity <= 0) {
            throw new WasteException('Waste quantity must be greater than zero.');
        }

        return WasteRecord::create([
            'batch_record_id' => $attributes['batch_record_id'] ?? null,
            'material_code' => $attributes['material_code'] ?? null,
            'material_description' => $attributes['material_description'] ?? null,
            'lot_number' => $attributes['lot_number'] ?? null,
            'quantity' => $quantity,
            'uom' => $attributes['uom'] ?? 'kg',
            'category' => $category,
            'reason' => $reason,
            'recorded_by' => $user?->id,
            'recorded_at' => now(),
        ]);
    }
}
