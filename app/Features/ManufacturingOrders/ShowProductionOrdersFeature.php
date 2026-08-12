<?php

namespace App\Features\ManufacturingOrders;

use App\Domains\WinMan\Jobs\SearchOutstandingManufacturingOrdersJob;

/**
 * Lists outstanding WinMan MOs for a single classification/UOM combination
 * (used by the IBC Production and Bucketing screens).
 */
class ShowProductionOrdersFeature
{
    public function __construct(
        private readonly SearchOutstandingManufacturingOrdersJob $searchOrders,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(int $classification, ?int $unitOfMeasure = null): array
    {
        $orders = ($this->searchOrders)(null, 250);

        return collect($orders)
            ->filter(fn ($order) => $order->classification === $classification
                && ($unitOfMeasure === null || $order->unitOfMeasure === $unitOfMeasure))
            ->sortBy('dueDate')
            ->values()
            ->map(static fn ($order): array => [
                'mo_ref' => $order->winmanManufacturingOrderId,
                'winman_mo' => $order->winmanManufacturingOrder,
                'product_id' => $order->winmanProductId,
                'product_description' => $order->productDescription,
                'outstanding' => $order->quantityOutstanding,
                'due_date' => $order->dueDate,
            ])
            ->all();
    }
}
