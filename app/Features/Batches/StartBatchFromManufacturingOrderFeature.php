<?php

namespace App\Features\Batches;

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Batch\Jobs\CreateBatchRecordJob;
use App\Domains\Batch\Jobs\GenerateBatchNumberJob;
use App\Models\BatchRecord;
use App\Models\RecipeVariant;
use App\Models\User;
use App\Operations\SelectManufacturingOrderOperation;

/**
 * Starts a manufacturing batch from an existing WinMan MO (scope acceptance
 * criteria 1-3, 6): selects the MO, enforces batch-variant selection where a
 * recipe has multiple approved batch sizes, and creates the batch record.
 */
class StartBatchFromManufacturingOrderFeature
{
    public function __construct(
        private readonly SelectManufacturingOrderOperation $selectManufacturingOrder,
        private readonly GenerateBatchNumberJob $generateBatchNumber,
        private readonly CreateBatchRecordJob $createBatchRecord,
    ) {
    }

    public function __invoke(
        int $winmanManufacturingOrder,
        ?int $variantId = null,
        ?float $plannedQuantityKg = null,
        ?User $user = null,
        ?string $shift = null,
    ): BatchRecord {
        $order = ($this->selectManufacturingOrder)($winmanManufacturingOrder, $user);

        if ((int) ($order->winman_classification ?? 0) !== 30) {
            throw new BatchException('Batch production workflow is available for Intermediate manufacturing orders only (classification 30).');
        }

        $variant = $this->resolveVariant($order->recipe_code, $variantId);

        $plannedQuantity = $this->resolvePlannedQuantity($order, $variant, $plannedQuantityKg);

        if ($variant !== null) {
            $order->forceFill(['variant_id' => $variant->id])->save();
        }

        $batchNumber = ($this->generateBatchNumber)();

        return ($this->createBatchRecord)($order, $batchNumber, $variant, $user, $shift, $plannedQuantity);
    }

    private function resolvePlannedQuantity(
        \App\Models\ManufacturingOrder $order,
        ?RecipeVariant $variant,
        ?float $requestedPlannedQuantity,
    ): float {
        $defaultPlanned = (float) ($variant?->batch_size ?? $order->planned_quantity ?? 0);
        $planned = $requestedPlannedQuantity !== null ? (float) $requestedPlannedQuantity : $defaultPlanned;

        if ($planned <= 0) {
            throw new BatchException('Batch quantity must be greater than zero.');
        }

        $outstanding = (float) ($order->quantity_outstanding ?? 0);
        if ($outstanding > 0 && $planned > $outstanding + 0.0001) {
            throw new BatchException('Batch quantity cannot exceed MO outstanding quantity.');
        }

        return round($planned, 3);
    }

    private function resolveVariant(?string $recipeCode, ?int $variantId): ?RecipeVariant
    {
        if ($variantId !== null) {
            $variant = RecipeVariant::query()
                ->where('id', $variantId)
                ->where('recipe_code', $recipeCode)
                ->where('active_flag', true)
                ->first();

            if ($variant === null) {
                throw new BatchException('The selected batch-size variant is not valid for this order.');
            }

            return $variant;
        }

        $hasVariants = $recipeCode !== null
            && RecipeVariant::query()
                ->where('recipe_code', $recipeCode)
                ->where('active_flag', true)
                ->exists();

        if ($hasVariants) {
            throw new BatchException('A batch-size variant must be selected for this recipe before starting a batch.');
        }

        return null;
    }
}
