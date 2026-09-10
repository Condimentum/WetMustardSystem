<?php

namespace App\Domains\Batch\Jobs;

use App\Models\BatchIngredientLot;
use App\Models\BatchRecord;

/**
 * Adds an ingredient lot row to a batch. A lot change creates a new row rather
 * than overwriting existing data (scope §11 validation).
 */
class AddIngredientLotJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(BatchRecord $batch, array $attributes): BatchIngredientLot
    {
        return $batch->ingredientLots()->create([
            'material_code' => $attributes['material_code'] ?? null,
            'material_description' => $attributes['material_description'] ?? null,
            'required_quantity' => $attributes['required_quantity'] ?? null,
            'uom' => $attributes['uom'] ?? null,
            'sequence' => $attributes['sequence'] ?? null,
            'lot_number' => $attributes['lot_number'] ?? null,
            'supplier_lot_number' => $attributes['supplier_lot_number'] ?? null,
            'actual_quantity' => $attributes['actual_quantity'] ?? null,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }
}
