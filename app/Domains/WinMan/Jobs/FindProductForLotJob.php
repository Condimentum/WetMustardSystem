<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Support\WinManConnection;

/**
 * Finds which product a WinMan lot number (for example a pallet / IBC SSCC) belongs to,
 * whatever the product. Stock still on hand wins over history.
 *
 * @return array{product_id: string, product_description: string, quantity_outstanding: float}|null
 */
class FindProductForLotJob
{
    public function __construct(
        private readonly WinManConnection $winman,
    ) {
    }

    /**
     * @return array{product_id: string, product_description: string, quantity_outstanding: float}|null
     */
    public function __invoke(string $lotNumber): ?array
    {
        $lotNumber = trim($lotNumber);

        if ($lotNumber === '') {
            return null;
        }

        $rows = $this->winman->connection()->select(
            'SELECT TOP 1 p.ProductId, p.ProductDescription, i.QuantityOutstanding
             FROM Inventory i
             JOIN Products p ON p.Product = i.Product
             WHERE LTRIM(RTRIM(i.LotNumber)) = ?
             ORDER BY CASE WHEN i.QuantityOutstanding <> 0 THEN 0 ELSE 1 END, i.Inventory DESC',
            [$lotNumber],
        );

        $row = $rows[0] ?? null;

        if ($row === null) {
            return null;
        }

        return [
            'product_id' => trim((string) $row->ProductId),
            'product_description' => trim((string) $row->ProductDescription),
            'quantity_outstanding' => (float) $row->QuantityOutstanding,
        ];
    }
}
