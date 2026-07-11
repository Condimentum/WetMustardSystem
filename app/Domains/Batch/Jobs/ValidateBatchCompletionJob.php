<?php

namespace App\Domains\Batch\Jobs;

use App\Models\BatchRecord;

/**
 * Validates a batch is ready for completion (scope §11 validation): mandatory
 * ingredient lots, lot numbers, actual quantities, and weighed/tipped sign-offs.
 *
 * @return array<int, string> Human-readable issues; empty when ready.
 */
class ValidateBatchCompletionJob
{
    /**
     * @return array<int, string>
     */
    public function __invoke(BatchRecord $batch): array
    {
        $batch->loadMissing('ingredientLots');

        $issues = [];

        if ($batch->ingredientLots->isEmpty()) {
            $issues[] = 'At least one ingredient lot must be recorded.';
        }

        foreach ($batch->ingredientLots as $lot) {
            $label = $lot->material_description ?: ($lot->material_code ?: "lot #{$lot->id}");

            if (blank($lot->lot_number)) {
                $issues[] = "Ingredient '{$label}' is missing a lot number.";
            }
            if ($lot->actual_quantity === null) {
                $issues[] = "Ingredient '{$label}' is missing an actual quantity.";
            }
            if ($lot->weighed_by === null) {
                $issues[] = "Ingredient '{$label}' is missing a weighed sign-off.";
            }
            if ($lot->tipped_by === null) {
                $issues[] = "Ingredient '{$label}' is missing a tipped sign-off.";
            }
        }

        return $issues;
    }
}
