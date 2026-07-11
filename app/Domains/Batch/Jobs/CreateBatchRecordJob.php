<?php

namespace App\Domains\Batch\Jobs;

use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\RecipeVariant;
use App\Models\User;

/**
 * Creates a manufacturing batch record header against a selected MO.
 *
 * Planned quantity is inherited from the selected batch-size variant where one
 * is chosen, otherwise from the MO planned quantity (no repeated master-data
 * entry, scope §6).
 */
class CreateBatchRecordJob
{
    public function __invoke(
        ManufacturingOrder $order,
        string $batchNumber,
        ?RecipeVariant $variant = null,
        ?User $user = null,
        ?string $shift = null,
        ?float $plannedQuantityKg = null,
    ): BatchRecord {
        return BatchRecord::create([
            'manufacturing_order_id' => $order->id,
            'product_id' => $order->product_id,
            'variant_id' => $variant?->id,
            'batch_number' => $batchNumber,
            'production_date' => now()->toDateString(),
            'shift' => $shift,
            'planned_quantity' => $plannedQuantityKg ?? ($variant?->batch_size ?? $order->planned_quantity),
            'status' => BatchRecord::STATUS_IN_PROGRESS,
            'created_by' => $user?->id,
        ]);
    }
}
