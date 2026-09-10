<?php

namespace App\Domains\Batch\Jobs;

use App\Models\BatchRecord;
use App\Models\PaperworkRow;

/**
 * Validates a batch is ready for completion (scope §11 validation): mandatory
 * ingredient lots with lot numbers and actual quantities, plus the batch-level
 * ingredients sign-off confirmations (powders/liquids weighed, batch tipped -
 * operator actions performed outside the app, confirmed once per batch).
 *
 * @return array<int, string> Human-readable issues; empty when ready.
 */
class ValidateBatchCompletionJob
{
    private const SIGNOFF_CONFIRMATIONS = [
        'ingredients_signoff.powders_weighed_by' => 'Powders Weighed',
        'ingredients_signoff.liquids_weighed_by' => 'Liquids Weighed',
        'ingredients_signoff.tipping_batch_by' => 'Tipping Batch',
    ];

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
        }

        $confirmations = PaperworkRow::query()
            ->where('batch_record_id', $batch->id)
            ->whereIn('row_key', array_keys(self::SIGNOFF_CONFIRMATIONS))
            ->pluck('value_text', 'row_key');

        foreach (self::SIGNOFF_CONFIRMATIONS as $rowKey => $label) {
            if (trim((string) ($confirmations[$rowKey] ?? '')) === '') {
                $issues[] = "Ingredients sign-off confirmation '{$label}' is missing.";
            }
        }

        return $issues;
    }
}
