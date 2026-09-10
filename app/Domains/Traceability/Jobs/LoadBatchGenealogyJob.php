<?php

namespace App\Domains\Traceability\Jobs;

use App\Models\BatchRecord;

/**
 * Eager-loads the full genealogy of a batch for a traceability view: MO,
 * ingredients, process steps, pallecons, metal detector checks, packing runs
 * (IBCs, weight checks, pallets), drum runs (pallets, drums) and packaging lots.
 */
class LoadBatchGenealogyJob
{
    public function __invoke(BatchRecord $batch): BatchRecord
    {
        return $batch->load([
            'manufacturingOrder',
            'product',
            'variant',
            'ingredientLots',
            'processSteps',
            'pallecons',
            'palleconContainers.fills.batchRecord',
            'metalDetectorChecks',
            'packingRuns.ibcs.palleconRecord',
            'packingRuns.weightChecks',
            'packingRuns.pallets',
            'drumProcessingRuns.pallets.drumRecords',
            'packagingLots',
        ]);
    }
}
