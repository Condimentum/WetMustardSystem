<?php

namespace App\Domains\Batch\Jobs;

use App\Models\BatchRecord;
use App\Models\PaperworkRow;
use App\Models\RecipeCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Seeds flat paperwork rows for a newly created batch.
 *
 * Each row repeats the MO + batch header context so a single table can later
 * be pivoted into one MO paperwork sheet with one column per batch.
 */
class SeedPaperworkRowsForBatchJob
{
    public function __invoke(BatchRecord $batch, ?string $recipeCode = null, ?User $actor = null): void
    {
        if (PaperworkRow::query()->where('batch_record_id', $batch->id)->exists()) {
            return;
        }

        $order = $batch->manufacturingOrder;
        if ($order === null) {
            return;
        }

        $resolvedRecipeCode = $recipeCode !== null && trim($recipeCode) !== ''
            ? trim($recipeCode)
            : (trim((string) ($order->recipe_code ?? '')) !== '' ? trim((string) $order->recipe_code) : null);

        $recipeCard = $resolvedRecipeCode === null
            ? null
            : RecipeCard::query()->where('recipe_code', $resolvedRecipeCode)->first();

        DB::transaction(function () use ($batch, $order, $recipeCard, $resolvedRecipeCode, $actor): void {
            $columnIndex = (int) (PaperworkRow::query()
                ->where('manufacturing_order_id', $order->id)
                ->max('batch_column_index') ?? 0) + 1;

            $context = [
                'manufacturing_order_id' => $order->id,
                'manufacturing_order_ref' => (string) ($order->winman_manufacturing_order_id ?? $order->mo_number),
                'batch_record_id' => $batch->id,
                'batch_number' => (string) $batch->batch_number,
                'batch_column_index' => $columnIndex,
                'product_id' => $batch->product_id,
                'recipe_code' => $resolvedRecipeCode,
                'recipe_revision' => $recipeCard?->revision_no,
                'status' => 'pending',
                'entered_by' => $actor?->id,
                'entered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
                'value_text' => null,
                'value_number' => null,
                'value_bool' => null,
                'value_datetime' => null,
                'unit' => null,
            ];

            $rows = [];
            $orderCounter = 1;

            $rows[] = array_merge($context, [
                'row_key' => 'header.batch_number',
                'row_label' => 'Batch Number',
                'row_order' => $orderCounter++,
                'value_text' => (string) $batch->batch_number,
            ]);

            $rows[] = array_merge($context, [
                'row_key' => 'header.batch_quantity_kg',
                'row_label' => 'Batch Quantity',
                'row_order' => $orderCounter++,
                'value_number' => $batch->planned_quantity,
                'unit' => 'kg',
            ]);

            $rows[] = array_merge($context, [
                'row_key' => 'header.recipe_code',
                'row_label' => 'Recipe Code',
                'row_order' => $orderCounter++,
                'value_text' => $resolvedRecipeCode,
            ]);

            $rows[] = array_merge($context, [
                'row_key' => 'header.recipe_revision',
                'row_label' => 'Recipe Revision',
                'row_order' => $orderCounter++,
                'value_text' => $recipeCard?->revision_no,
            ]);

            $rows[] = array_merge($context, [
                'row_key' => 'header.production_date',
                'row_label' => 'Production Date',
                'row_order' => $orderCounter++,
                'value_datetime' => $batch->production_date?->toDateString(),
            ]);

            $rows[] = array_merge($context, [
                'row_key' => 'header.shift',
                'row_label' => 'Shift',
                'row_order' => $orderCounter++,
                'value_text' => $batch->shift,
            ]);

            $steps = is_array($recipeCard?->steps) ? array_values($recipeCard->steps) : [];
            foreach ($steps as $i => $step) {
                $rows[] = array_merge($context, [
                    'row_key' => sprintf('recipe.step.%03d', $i + 1),
                    'row_label' => 'Recipe Step '.($i + 1),
                    'row_order' => $orderCounter++,
                    'value_text' => trim((string) $step),
                ]);
            }

            PaperworkRow::query()->insert($rows);
        });
    }
}
