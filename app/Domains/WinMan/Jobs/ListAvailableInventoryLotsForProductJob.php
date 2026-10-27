<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Support\WinManConnection;

/**
 * Returns available inventory lots in WinMan for a specific ProductId.
 *
 * The result is grouped by LotNumber and uses QuantityOutstanding as the
 * available amount for operator lot selection.
 *
 * @return array<int, array{lot_number: string, quantity_outstanding: float}>
 */
class ListAvailableInventoryLotsForProductJob
{
    public function __construct(
        private readonly WinManConnection $winman,
    ) {
    }

    /**
     * @param string|null $lotNumber When set, restricts the result to that exact lot (case-insensitive per WinMan collation).
     *
     * @return array<int, array{lot_number: string, quantity_outstanding: float}>
     */
    public function __invoke(string $productId, int $limit = 100, ?string $lotNumber = null): array
    {
        $productId = trim($productId);

        if ($productId === '') {
            return [];
        }

        $lotNumber = $lotNumber !== null ? trim($lotNumber) : null;
        $lotFilter = $lotNumber !== null && $lotNumber !== '' ? 'AND LTRIM(RTRIM(i.LotNumber)) = ?' : '';
        $bindings = [$limit, $productId];
        if ($lotFilter !== '') {
            $bindings[] = $lotNumber;
        }

        $rows = $this->winman->connection()->select(
            "SELECT TOP (?)
                    i.LotNumber,
                    SUM(i.QuantityOutstanding) AS QuantityOutstanding
             FROM Inventory i
             JOIN Products p ON p.Product = i.Product
             WHERE p.ProductId = ?
                 AND i.QuantityOutstanding > 0
                 AND i.LotNumber IS NOT NULL
                 AND i.LotNumber <> ''
                 {$lotFilter}
             GROUP BY i.LotNumber
             ORDER BY i.LotNumber ASC",
            $bindings,
        );

        return array_map(static fn (object $row): array => [
            'lot_number' => (string) $row->LotNumber,
            'quantity_outstanding' => (float) $row->QuantityOutstanding,
        ], $rows);
    }
}
