<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Support\WinManConnection;

/**
 * Lists historical lot numbers associated with a WorkInProgress line in WinMan.
 *
 * Uses current and archived inventory rows linked to the WIP line.
 *
 * @return array<int, array{lot_number:string,quantity:float,last_effective_date:?string}>
 */
class ListIssuedLotsForWorkInProgressJob
{
    public function __construct(
        private readonly WinManConnection $winman,
    ) {
    }

    /**
     * @return array<int, array{lot_number:string,quantity:float,last_effective_date:?string}>
     */
    public function __invoke(int $workInProgress, int $product, int $limit = 50): array
    {
        if ($workInProgress <= 0 || $product <= 0) {
            return [];
        }

        $rows = $this->winman->connection()->select(
            "SELECT LotNumber, SUM(Quantity) AS Quantity, MAX(EffectiveDate) AS LastEffectiveDate
             FROM (
                 SELECT LotNumber, Quantity, EffectiveDate
                 FROM Inventory
                 WHERE WorkInProgress = ?
                   AND Product = ?
                   AND ISNULL(LotNumber, '') <> ''
                 UNION ALL
                 SELECT LotNumber, Quantity, EffectiveDate
                 FROM InventoryArchive
                 WHERE WorkInProgress = ?
                   AND Product = ?
                   AND ISNULL(LotNumber, '') <> ''
             ) x
             GROUP BY LotNumber
             ORDER BY MAX(EffectiveDate) DESC",
            [$workInProgress, $product, $workInProgress, $product],
        );

        return array_slice(array_map(
            static fn (object $row): array => [
                'lot_number' => (string) ($row->LotNumber ?? ''),
                'quantity' => (float) ($row->Quantity ?? 0),
                'last_effective_date' => isset($row->LastEffectiveDate) ? (string) $row->LastEffectiveDate : null,
            ],
            $rows,
        ), 0, max(1, $limit));
    }
}
