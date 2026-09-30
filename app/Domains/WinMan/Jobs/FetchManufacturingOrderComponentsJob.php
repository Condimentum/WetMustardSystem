<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Data\ComponentData;
use App\Domains\WinMan\Support\WinManConnection;

/**
 * Fetches the live WinMan Work In Progress component list for a selected MO
 * (scope §11.3), joined to Products for description and classification.
 *
 * Only component/material item types (those carrying a Product) are returned;
 * routing/resource lines are excluded. Read-only.
 *
 * @return array<int, ComponentData>
 */
class FetchManufacturingOrderComponentsJob
{
    public function __construct(
        private readonly WinManConnection $winman,
    ) {
    }

    /**
     * @return array<int, ComponentData>
     */
    public function __invoke(int $winmanManufacturingOrder): array
    {
        $itemTypes = array_values((array) config('winman.component_item_types', ['C']));

        $bindings = [$winmanManufacturingOrder];
        foreach ($itemTypes as $type) {
            $bindings[] = $type;
        }
        $typePlaceholders = implode(', ', array_fill(0, count($itemTypes), '?'));

                $sql = "SELECT w.WorkInProgress, w.ItemType, w.Product, p.ProductId, p.ProductDescription, p.Classification,
                       w.Quantity, w.QuantityIssued, w.QuantityOutstanding
                FROM WorkInProgress w
                JOIN Products p ON p.Product = w.Product
                WHERE w.ManufacturingOrder = ?
                  AND w.ItemType IN ({$typePlaceholders})
                ORDER BY w.SeqNumber";

        $rows = $this->winman->connection()->select($sql, $bindings);

        return array_map(
            static fn (object $row): ComponentData => ComponentData::fromRow($row),
            $rows,
        );
    }
}
