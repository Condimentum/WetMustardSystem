<?php

namespace App\Domains\ManufacturingOrder\Jobs;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\User;

/**
 * Persists (or refreshes) a selected WinMan MO as a local DBMTS record.
 *
 * Stores both the internal WinMan ManufacturingOrder BIGINT and the human
 * readable ManufacturingOrderId, and resolves the local ProductMaster mapping
 * from the WinMan ProductId. Idempotent on the internal WinMan MO id.
 */
class StoreSelectedManufacturingOrderJob
{
    public function __invoke(ManufacturingOrderData $data, ?User $user = null): ManufacturingOrder
    {
        $product = Product::query()
            ->where(function ($query) use ($data): void {
                $query->where('winman_product_id', $data->winmanProductId)
                    ->orWhere('finished_goods_code', $data->winmanProductId);
            })
            ->first();

        return ManufacturingOrder::updateOrCreate(
            ['winman_manufacturing_order' => $data->winmanManufacturingOrder],
            [
                'mo_number' => $data->winmanManufacturingOrderId,
                'winman_manufacturing_order_id' => $data->winmanManufacturingOrderId,
                'winman_product_internal' => (string) $data->winmanProductInternal,
                'winman_product_id' => $data->winmanProductId,
                'recipe_code' => $product?->recipe_code,
                'product_id' => $product?->id,
                'planned_quantity' => $data->plannedQuantity,
                'quantity_outstanding' => $data->quantityOutstanding,
                'winman_classification' => $data->classification,
                'winman_system_type' => $data->systemType,
                'winman_unit_of_measure' => $data->unitOfMeasure,
                'winman_unit_of_measure_description' => $data->unitOfMeasureDescription,
                'winman_last_modified_date' => $data->lastModifiedDate,
                'status' => 'selected',
                'selected_by' => $user?->id,
            ],
        );
    }
}
