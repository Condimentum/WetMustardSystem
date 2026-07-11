<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Support\WinManConnection;

/**
 * Fetches a single WinMan manufacturing order by its internal BIGINT id,
 * joined to Products. Read-only.
 *
 * Returns authoritative current values (including QuantityOutstanding and
 * LastModifiedDate) used for selection and, later, pre-booking concurrency
 * checks (scope §11.5).
 */
class FetchManufacturingOrderJob
{
    public function __construct(
        private readonly WinManConnection $winman,
    ) {
    }

    public function __invoke(int $winmanManufacturingOrder): ?ManufacturingOrderData
    {
        $row = $this->winman->connection()->selectOne(
            'SELECT mo.ManufacturingOrder, mo.ManufacturingOrderId, mo.Product,
                    mo.SystemType, mo.Quantity, mo.QuantityOutstanding, mo.DueDate, mo.LastModifiedDate,
                    p.ProductId, p.ProductDescription, p.Classification, p.UnitOfMeasure,
                    u.UnitOfMeasureDescription
             FROM ManufacturingOrders mo
             JOIN Products p ON p.Product = mo.Product
             LEFT JOIN UnitsOfMeasure u ON u.UnitOfMeasure = p.UnitOfMeasure
             WHERE mo.ManufacturingOrder = ?',
            [$winmanManufacturingOrder],
        );

        return $row !== null ? ManufacturingOrderData::fromRow($row) : null;
    }
}
