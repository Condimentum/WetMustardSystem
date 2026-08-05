<?php

namespace App\Features\Batches;

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Batch\Jobs\CreateBatchRecordJob;
use App\Domains\Batch\Jobs\GenerateBatchNumberJob;
use App\Domains\Batch\Jobs\SeedPaperworkRowsForBatchJob;
use App\Models\BatchRecord;
use App\Models\ProductMapping;
use App\Models\RecipeCard;
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
        private readonly SeedPaperworkRowsForBatchJob $seedPaperworkRows,
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
        $recipeCode = $this->resolveRecipeCode($order, $variant);
        if ($recipeCode === null) {
            throw new BatchException('No recipe mapping found for this manufacturing order product. Sync Product Mapping and set the recipe card batch size before creating a batch.');
        }

        $plannedQuantity = $this->resolvePlannedQuantityFromRecipeCode($order, $recipeCode, $plannedQuantityKg);

        if ((string) ($order->recipe_code ?? '') === '') {
            $order->forceFill(['recipe_code' => $recipeCode])->save();
        }

        if ($variant !== null) {
            $order->forceFill(['variant_id' => $variant->id])->save();
        }

        $batchNumber = ($this->generateBatchNumber)();

        $batch = ($this->createBatchRecord)($order, $batchNumber, $variant, null, $user, $shift, $plannedQuantity);
        ($this->seedPaperworkRows)($batch->fresh('manufacturingOrder'), $recipeCode, $user);

        return $batch;
    }

    private function resolvePlannedQuantityFromRecipeCode(
        \App\Models\ManufacturingOrder $order,
        string $recipeCode,
        ?float $selectedBatchSizeKg = null,
    ): float {
        $batchSizes = $this->resolveRecipeBatchSizes($recipeCode);

        if ($batchSizes === []) {
            throw new BatchException('Recipe batch quantity is not stored for recipe '.$recipeCode.'. Add batch size on Settings > Recipes before creating a batch.');
        }

        if (count($batchSizes) > 1) {
            if ($selectedBatchSizeKg === null) {
                throw new BatchException('Multiple batch sizes are configured for recipe '.$recipeCode.'. Select a batch size before creating a batch.');
            }

            $selectedRounded = round($selectedBatchSizeKg, 3);
            $planned = collect($batchSizes)
                ->first(fn (float $size): bool => abs($size - $selectedRounded) < 0.0001);

            if (! is_float($planned) && ! is_int($planned)) {
                throw new BatchException('Selected batch size is not valid for recipe '.$recipeCode.'.');
            }

            $planned = (float) $planned;
        } else {
            $planned = (float) $batchSizes[0];
        }

        if ($planned <= 0) {
            throw new BatchException('Recipe batch quantity is not stored for recipe '.$recipeCode.'. Add batch size on Settings > Recipes before creating a batch.');
        }

        $outstanding = (float) ($order->quantity_outstanding ?? 0);
        if ($outstanding > 0 && $planned > $outstanding + 0.0001) {
            throw new BatchException('Recipe batch quantity ('.$planned.' kg) exceeds MO outstanding quantity.');
        }

        return round($planned, 3);
    }

    /** @return array<int, float> */
    private function resolveRecipeBatchSizes(string $recipeCode): array
    {
        $card = RecipeCard::query()
            ->where('recipe_code', $recipeCode)
            ->first(['batch_size_kg', 'batch_sizes_kg']);

        if ($card === null) {
            return [];
        }

        $sizes = [];

        if (is_array($card->batch_sizes_kg)) {
            foreach ($card->batch_sizes_kg as $size) {
                if (! is_numeric($size)) {
                    continue;
                }

                $numeric = round((float) $size, 3);
                if ($numeric > 0) {
                    $sizes[] = $numeric;
                }
            }
        }

        if ($card->batch_size_kg !== null) {
            $legacy = round((float) $card->batch_size_kg, 3);
            if ($legacy > 0) {
                $sizes[] = $legacy;
            }
        }

        $sizes = array_values(array_unique($sizes, SORT_NUMERIC));
        sort($sizes, SORT_NUMERIC);

        return $sizes;
    }

    private function resolveRecipeCode(\App\Models\ManufacturingOrder $order, ?RecipeVariant $variant): ?string
    {
        $variantRecipeCode = $variant?->recipe_code !== null && trim((string) $variant->recipe_code) !== ''
            ? trim((string) $variant->recipe_code)
            : null;
        if ($variantRecipeCode !== null) {
            return $variantRecipeCode;
        }

        $orderRecipeCode = $order->recipe_code !== null && trim((string) $order->recipe_code) !== ''
            ? trim((string) $order->recipe_code)
            : null;
        if ($orderRecipeCode !== null) {
            return $orderRecipeCode;
        }

        $structureProductId = trim((string) ($order->winman_product_id ?? ''));
        if ($structureProductId === '') {
            return null;
        }

        $directRecipe = ProductMapping::query()
            ->where('structure_product_id', $structureProductId)
            ->where(function ($query): void {
                $query->where('component_product_id', 'like', '3001%')
                    ->orWhere('component_product_id', 'like', '9900%');
            })
            ->orderBy('structure_level')
            ->value('component_product_id');

        if (is_string($directRecipe) && trim($directRecipe) !== '') {
            return trim($directRecipe);
        }

        $intermediate = ProductMapping::query()
            ->where('structure_product_id', $structureProductId)
            ->where('component_product_id', 'like', '5001%')
            ->orderBy('structure_level')
            ->value('component_product_id');

        if (! is_string($intermediate) || trim($intermediate) === '') {
            return null;
        }

        $nestedRecipe = ProductMapping::query()
            ->where('structure_product_id', trim($intermediate))
            ->where(function ($query): void {
                $query->where('component_product_id', 'like', '3001%')
                    ->orWhere('component_product_id', 'like', '9900%');
            })
            ->orderBy('structure_level')
            ->value('component_product_id');

        return is_string($nestedRecipe) && trim($nestedRecipe) !== ''
            ? trim($nestedRecipe)
            : null;
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
            return null;
        }

        return null;
    }
}
