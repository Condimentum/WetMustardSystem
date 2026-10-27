<?php

namespace App\Features\Batches;

use App\Domains\WinMan\Jobs\ListAvailableInventoryLotsForProductJob;

/**
 * Retrieves currently available WinMan inventory lots for the selected
 * ingredient product code.
 *
 * @return array<int, array{lot_number: string, quantity_outstanding: float}>
 */
class GetAvailableIngredientLotsFeature
{
    public function __construct(
        private readonly ListAvailableInventoryLotsForProductJob $listLots,
    ) {
    }

    /**
     * @return array<int, array{lot_number: string, quantity_outstanding: float}>
     */
    public function __invoke(?string $productId, int $limit = 100, ?string $lotNumber = null): array
    {
        if ($productId === null || trim($productId) === '') {
            return [];
        }

        return ($this->listLots)($productId, $limit, $lotNumber);
    }
}
