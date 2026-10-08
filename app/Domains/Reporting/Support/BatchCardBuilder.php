<?php

namespace App\Domains\Reporting\Support;

use App\Domains\WinMan\Support\WinManConnection;
use App\Models\BatchRecord;
use App\Models\ProductMapping;
use App\Models\Recipe;
use App\Models\RecipeCard;
use App\Models\RecipeIngredient;
use App\Models\WinManBookingLog;
use App\Models\WinManIssueLog;
use App\Support\FeatureSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds batch card ("BATCH CARD & PROCESS SHEET") sections from batch
 * records: recipe ingredients scaled to batch size, issued lots, weighed /
 * tipped marks, sign-offs, process settings, label batch numbers and steps.
 * One section per MO and sheet - batches 1-8 on sheet 1, 9-16 on sheet 2...
 *
 * Shared by the pallecon DailyIntermediateProductionReport and the
 * recipe-driven RecipeBatchCardReport.
 */
class BatchCardBuilder
{
    /** Batch columns on one sheet. */
    public const BATCH_COLUMNS = 8;

    /** Relations each batch needs loaded before building sections. */
    public const BATCH_RELATIONS = ['product', 'manufacturingOrder', 'pallecons', 'componentSnapshots', 'ingredientLots.weighedBy', 'ingredientLots.tippedBy', 'paperworkRows', 'bookingLogs'];

    /**
     * @param  Collection<int, BatchRecord>  $batches
     * @param  int|null  $stepLimit  null prints every recipe step
     * @return array<int, array<string, mixed>>
     */
    public function sections(Collection $batches, ?int $stepLimit = 9): array
    {
        $recipeContextByBatchId = $this->resolveRecipeContextForBatches($batches);
        $issueLotsByBatchAndMaterial = $this->resolveIssuedLotsByBatchAndMaterial($batches);
        $labelBatchNumbersByBatch = $this->resolveLabelBatchNumbersByBatch($batches);

        $recipeCodes = $batches
            ->map(fn (BatchRecord $batch): string => $this->recipeCodeFor($batch, $recipeContextByBatchId))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $recipeCards = RecipeCard::query()
            ->whereIn('recipe_code', $recipeCodes)
            ->get()
            ->keyBy('recipe_code');

        $sections = $this->buildDocumentSections(
            $batches,
            $recipeCards,
            $recipeContextByBatchId,
            $issueLotsByBatchAndMaterial,
            $labelBatchNumbersByBatch,
            $stepLimit,
        );

        return $this->consolidateSectionsByMo($sections);
    }

    /**
     * Recipe code for each batch: from Product Mapping (via the MO's WinMan
     * product), falling back to the MO's recipe code.
     *
     * @param  Collection<int, BatchRecord>  $batches
     * @return array<int, string>
     */
    public function recipeCodesByBatch(Collection $batches): array
    {
        $context = $this->resolveRecipeContextForBatches($batches);

        return $batches
            ->mapWithKeys(fn (BatchRecord $batch): array => [(int) $batch->id => $this->recipeCodeFor($batch, $context)])
            ->all();
    }

    /**
     * A recipe's ingredients (scaled to its first batch size) and steps, for
     * the batch card preview in Settings > Documents.
     *
     * @return array{recipe_code: string, description: string, plc_recipe_number: string, components: array<int, array<string, string>>, percent_total: string, quantity_total: string, steps: array<int, string>}
     */
    public function recipePreview(string $recipeCode, ?float $batchSizeKg = null): array
    {
        $recipeCard = RecipeCard::query()->where('recipe_code', $recipeCode)->first();
        $batchSizes = collect(is_array($recipeCard?->batch_sizes_kg) ? $recipeCard->batch_sizes_kg : [])
            ->push($recipeCard?->batch_size_kg)
            ->filter(fn ($size): bool => is_numeric($size) && (float) $size > 0)
            ->map(fn ($size): float => round((float) $size, 3))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // An unsaved batch planned at the chosen size scales the ingredients like a real batch would.
        $previewBatch = new BatchRecord(['planned_quantity' => $batchSizeKg ?? ($batchSizes[0] ?? null)]);

        try {
            $rows = $this->resolveIngredientRows($previewBatch, $recipeCode, $recipeCard)->sortBy('sequence')->values();
        } catch (\Throwable) {
            $rows = collect(); // e.g. WinMan unreachable for a recipe with no ingredients in the app
        }

        $totalQty = (float) $rows->sum(fn (array $row): float => (float) ($row['quantity'] ?? 0));
        $components = $rows->map(function (array $row) use ($totalQty): array {
            $qty = (float) ($row['quantity'] ?? 0);
            $percent = isset($row['percentage']) ? (float) $row['percentage'] : ($totalQty > 0 ? ($qty / $totalQty) * 100 : 0);

            return [
                'allergen_material' => '',
                'material_code' => (string) ($row['material_code'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'percent' => number_format($percent, 2, '.', ''),
                'quantity' => number_format($qty, 3, '.', ''),
                'uom' => (string) ($row['uom'] ?? 'KG'),
                'lot_number' => '',
            ];
        })->all();

        $description = ProductMapping::query()
            ->where('component_product_id', $recipeCode)
            ->whereNotNull('component_product_description')
            ->value('component_product_description');

        return [
            'recipe_code' => $recipeCode,
            'batch_size_kg' => $this->resolveDocumentBatchSizeKg($previewBatch, $recipeCard),
            'batch_sizes' => $batchSizes,
            'description' => strtoupper(trim((string) $description)),
            'plc_recipe_number' => (string) ($recipeCard?->plc_recipe_number ?? '—'),
            'components' => $components,
            'percent_total' => number_format((float) collect($components)->sum(fn (array $c): float => (float) $c['percent']), 2, '.', ''),
            'quantity_total' => number_format($totalQty, 3, '.', ''),
            'steps' => is_array($recipeCard?->steps) ? array_values(array_map('strval', $recipeCard->steps)) : [],
        ];
    }

    /** 1-based batch column across sheets (column 9 is the first column of sheet 2). */
    public function columnIndex(BatchRecord $batch): int
    {
        return max(1, (int) ($batch->paperworkRows->first()?->batch_column_index ?? 1));
    }

    /** @param array<int, array{recipe_code: string, recipe_description: string|null}> $context */
    private function recipeCodeFor(BatchRecord $batch, array $context): string
    {
        $resolved = trim((string) ($context[$batch->id]['recipe_code'] ?? ''));

        return $resolved !== '' ? $resolved : trim((string) ($batch->manufacturingOrder?->recipe_code ?? ''));
    }

    /** 0-based column on the batch's own sheet. */
    private function sheetPosition(BatchRecord $batch): int
    {
        return ($this->columnIndex($batch) - 1) % self::BATCH_COLUMNS;
    }

    /** @return array<int, string> one cell per batch column, $value at $position */
    private function placeInColumn(int $position, string $value): array
    {
        $cells = array_fill(0, self::BATCH_COLUMNS, '');
        $cells[max(0, min(self::BATCH_COLUMNS - 1, $position))] = $value;

        return $cells;
    }

    /**
     * @param  array<int, string>  $existing
     * @param  array<int, string>  $incoming
     * @return array<int, string>
     */
    private function mergeColumnCells(array $existing, array $incoming): array
    {
        $merged = array_pad($existing, self::BATCH_COLUMNS, '');
        foreach (array_pad($incoming, self::BATCH_COLUMNS, '') as $i => $value) {
            if (trim((string) $merged[$i]) === '' && trim((string) $value) !== '') {
                $merged[$i] = $value;
            }
        }

        return $merged;
    }

    /** 1-based sheet number for the batch. */
    private function sheetNumber(BatchRecord $batch): int
    {
        return intdiv($this->columnIndex($batch) - 1, self::BATCH_COLUMNS) + 1;
    }

    /** @param Collection<int, BatchRecord> $batches
     *  @param Collection<int, RecipeCard> $recipeCards
     *  @param array<int, array{recipe_code: string, recipe_description: string|null}> $recipeContextByBatchId
     *  @param array<int, array<string, string>> $issueLotsByBatchAndMaterial
     *  @param array<int, array<int, string>> $labelBatchNumbersByBatch
     *  @return array<int, array<string, mixed>>
     */
    private function buildDocumentSections(
        Collection $batches,
        Collection $recipeCards,
        array $recipeContextByBatchId,
        array $issueLotsByBatchAndMaterial,
        array $labelBatchNumbersByBatch,
        ?int $stepLimit,
    ): array
    {
        return $batches->map(function (BatchRecord $batch) use ($recipeCards, $recipeContextByBatchId, $issueLotsByBatchAndMaterial, $labelBatchNumbersByBatch, $stepLimit): array {
            $mo = $batch->manufacturingOrder;
            $recipeCode = $this->recipeCodeFor($batch, $recipeContextByBatchId);
            $recipeCard = $recipeCode !== '' ? $recipeCards->get($recipeCode) : null;
            $resolvedRecipeDescription = trim((string) ($recipeContextByBatchId[$batch->id]['recipe_description'] ?? ''));
            $recipeDescription = strtoupper(trim((string) ($resolvedRecipeDescription !== ''
                ? $resolvedRecipeDescription
                : ($mo?->winman_product_description ?? $batch->product?->product_name ?? 'WET MUSTARD'))));
            $documentReference = strtoupper(trim((string) ($recipeCard?->document_reference ?? '')));
            $headerReferenceLine = strtoupper(trim(($documentReference !== '' ? $documentReference.' - ' : '').($recipeCode !== '' ? $recipeCode.' ' : '').$recipeDescription));
            if ($headerReferenceLine === '') {
                $headerReferenceLine = (string) ($mo?->winman_product_id ?? 'WMXXX');
            }

            $issuedLotsForBatch = $issueLotsByBatchAndMaterial[(int) $batch->id] ?? [];
            $ingredientSignoffs = $this->resolveIngredientSignoffsForBatch($batch);
            $ingredientSignoffSummary = $this->resolveIngredientSignoffSummaryForBatch($batch);
            $processSettingsSummary = $this->resolveProcessSettingsSummaryForBatch($batch);
            $sheetPosition = $this->sheetPosition($batch);
            $batchColumnIndex = $sheetPosition + 1;

            $ingredientRows = $this->resolveIngredientRows($batch, $recipeCode, $recipeCard)
                ->sortBy('sequence')
                ->values();

            $totalQty = (float) $ingredientRows->sum(fn (array $row): float => (float) ($row['quantity'] ?? 0));

            $componentRows = $ingredientRows->map(function (array $row) use ($totalQty, $issuedLotsForBatch, $ingredientSignoffs, $batchColumnIndex): array {
                $qty = (float) ($row['quantity'] ?? 0);
                $storedPercent = isset($row['percentage']) ? (float) $row['percentage'] : null;
                $percent = $storedPercent !== null
                    ? $storedPercent
                    : ($totalQty > 0 ? ($qty / $totalQty) * 100 : 0);
                $materialCode = (string) ($row['material_code'] ?? '');
                $description = (string) ($row['description'] ?? '');

                return [
                    'allergen_material' => '',
                    'material_code' => $materialCode,
                    'description' => $description,
                    'percent' => number_format($percent, 2, '.', ''),
                    'quantity' => number_format($qty, 3, '.', ''),
                    'uom' => (string) ($row['uom'] ?? 'KG'),
                    'lot_number' => $materialCode !== '' ? (string) ($issuedLotsForBatch[$materialCode] ?? '') : '',
                    'batch_marks' => $this->buildIngredientBatchMarks($materialCode, $description, $ingredientSignoffs, $batchColumnIndex),
                ];
            })->all();

            if ($componentRows === []) {
                $componentRows[] = [
                    'allergen_material' => '',
                    'material_code' => '',
                    'description' => 'No recipe ingredient rows found for this recipe.',
                    'percent' => '0.00',
                    'quantity' => '0.000',
                    'uom' => 'KG',
                    'lot_number' => '',
                    'batch_marks' => array_fill(0, 8, ''),
                ];
            }

            $defaultSteps = [
                'MIX FOR 10MINS - AGITATOR ON [CHECK FOR LUMPS BEFORE DISCHARGING, MIX FOR LONGER IF LUMPS ARE PRESENT]',
                'MILL THROUGH STONE MILL - AGITATOR ON',
                'PASS THROUGH DIJON SIFTER',
                'DEAERATE MILLED PRODUCT',
                'TAKE QC SAMPLE & TEST.',
                'METAL DETECT',
                'PACK & APPLY LABEL & DATE CODE',
            ];

            $steps = is_array($recipeCard?->steps) && $recipeCard->steps !== []
                ? array_values(array_map(static fn ($step): string => (string) $step, $recipeCard->steps))
                : $defaultSteps;

            $stepRows = collect($steps)
                ->when($stepLimit !== null, fn (Collection $collection): Collection => $collection->take($stepLimit))
                ->values()
                ->map(fn (string $step, int $index): array => [
                    'number' => $index + 1,
                    'text' => $step,
                ])
                ->all();

            if ($stepRows === []) {
                $stepRows[] = [
                    'number' => 1,
                    'text' => 'No stored process steps on recipe card.',
                ];
            }

            return [
                'header_reference_line' => $headerReferenceLine,
                'document_reference' => $documentReference !== '' ? $documentReference : '—',
                'revision_no' => (string) ($recipeCard?->revision_no ?? '—'),
                'issue_date' => $recipeCard?->issue_date?->format('d/m/Y') ?? '—',
                'reason_for_issue' => (string) ($recipeCard?->reason_for_issue ?? '—'),
                'recipe_code' => $recipeCode !== '' ? $recipeCode : '—',
                'plc_recipe_number' => (string) ($recipeCard?->plc_recipe_number ?? '—'),
                'product_description' => $recipeDescription !== '' ? $recipeDescription : '—',
                'batch_number' => (string) ($batch->batch_number ?? '—'),
                'batch_column_index' => $batchColumnIndex,
                'sheet_number' => $this->sheetNumber($batch),
                // Quantities are per batch size, so a different size starts its own sheet (see consolidateSectionsByMo).
                'batch_size_kg' => $this->resolveDocumentBatchSizeKg($batch, $recipeCard),
                'batch_offset' => ($this->sheetNumber($batch) - 1) * self::BATCH_COLUMNS,
                'batch_count' => 1,
                'mo_number' => (string) ($mo?->winman_manufacturing_order_id ?? $mo?->mo_number ?? '—'),
                'components' => $componentRows,
                'merge_allergen_material' => false,
                'label_batch_numbers' => $labelBatchNumbersByBatch[(int) $batch->id] ?? array_fill(0, 8, ''),
                'quantity_total' => number_format($totalQty, 3, '.', ''),
                'percent_total' => number_format((float) collect($componentRows)->sum(fn (array $r): float => (float) ($r['percent'] ?? 0)), 2, '.', ''),
                'powders_weighed_by' => $ingredientSignoffSummary['powders_weighed_by'],
                'liquids_weighed_by' => $ingredientSignoffSummary['liquids_weighed_by'],
                'tipping_batch_by' => $ingredientSignoffSummary['tipping_batch_by'],
                'powders_weighed_date' => $ingredientSignoffSummary['powders_weighed_date'],
                'liquids_weighed_date' => $ingredientSignoffSummary['liquids_weighed_date'],
                'tipping_batch_date' => $ingredientSignoffSummary['tipping_batch_date'],
                // Per batch column ("initials\ndate"), so each batch's sign-off sits under its own column.
                'signoff_columns' => [
                    'powders' => $this->placeInColumn($sheetPosition, $ingredientSignoffSummary['powders_weighed_by']."\n".$ingredientSignoffSummary['powders_weighed_date']),
                    'liquids' => $this->placeInColumn($sheetPosition, $ingredientSignoffSummary['liquids_weighed_by']."\n".$ingredientSignoffSummary['liquids_weighed_date']),
                    'tipping' => $this->placeInColumn($sheetPosition, $ingredientSignoffSummary['tipping_batch_by']."\n".$ingredientSignoffSummary['tipping_batch_date']),
                ],
                'process_columns' => [
                    'mill_gap_size_used' => $this->placeInColumn($sheetPosition, $processSettingsSummary['mill_gap_size_used']),
                    'p1_speed_used' => $this->placeInColumn($sheetPosition, $processSettingsSummary['p1_speed_used']),
                    'p2_speed_used' => $this->placeInColumn($sheetPosition, $processSettingsSummary['p2_speed_used']),
                ],
                'mill_gap_size_used' => $processSettingsSummary['mill_gap_size_used'],
                'p1_speed_used' => $processSettingsSummary['p1_speed_used'],
                'p2_speed_used' => $processSettingsSummary['p2_speed_used'],
                'steps' => $stepRows,
            ];
        })->all();
    }

    /**
     * @return Collection<int, array{material_code: string, description: string, quantity: float, uom: string, sequence: int}>
     */
    private function resolveIngredientRows(BatchRecord $batch, string $recipeCode, ?RecipeCard $recipeCard = null): Collection
    {
        if ($recipeCode === '') {
            return collect();
        }

        $batchSizeKg = $this->resolveDocumentBatchSizeKg($batch, $recipeCard);
        $recipe = Recipe::query()->where('recipe_code', $recipeCode)->first();
        if ($recipe === null) {
            return $this->resolveWinManStructureIngredientRows($recipeCode, $batchSizeKg);
        }

        $variantId = $batch->variant_id;
        if ($variantId !== null) {
            $variantRows = RecipeIngredient::query()
                ->where('recipe_id', $recipe->id)
                ->where('variant_id', $variantId)
                ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sequence')
                ->orderBy('material_code')
                ->get();

            if ($variantRows->isNotEmpty()) {
                return $variantRows->map(function (RecipeIngredient $ingredient): array {
                    return [
                        'material_code' => trim((string) $ingredient->material_code),
                        'description' => trim((string) ($ingredient->material_description ?? '')),
                        'percentage' => $ingredient->percentage !== null ? (float) $ingredient->percentage : null,
                        'quantity' => (float) ($ingredient->required_quantity ?? 0),
                        'uom' => trim((string) ($ingredient->uom ?? 'KG')),
                        'sequence' => (int) ($ingredient->sequence ?? 99999),
                    ];
                })->values();
            }
        }

        $baseRows = RecipeIngredient::query()
            ->where('recipe_id', $recipe->id)
            ->whereNull('variant_id')
            ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sequence')
            ->orderBy('material_code')
            ->get();

        if ($baseRows->isEmpty()) {
            $baseRows = RecipeIngredient::query()
                ->where('recipe_id', $recipe->id)
                ->orderByRaw('CASE WHEN sequence IS NULL THEN 1 ELSE 0 END')
                ->orderBy('sequence')
                ->orderBy('material_code')
                ->get();
        }

        return $baseRows
            ->map(function (RecipeIngredient $ingredient): array {
                return [
                    'material_code' => trim((string) $ingredient->material_code),
                    'description' => trim((string) ($ingredient->material_description ?? '')),
                    'percentage' => $ingredient->percentage !== null ? (float) $ingredient->percentage : null,
                    'quantity' => (float) ($ingredient->required_quantity ?? 0),
                    'uom' => trim((string) ($ingredient->uom ?? 'KG')),
                    'sequence' => (int) ($ingredient->sequence ?? 99999),
                ];
            })
            ->values()
            ->whenEmpty(fn (Collection $rows): Collection => $this->resolveWinManStructureIngredientRows($recipeCode, $batchSizeKg));
    }

    /**
     * @return Collection<int, array{material_code: string, description: string, percentage: float|null, quantity: float, uom: string, sequence: int}>
     */
    private function resolveWinManStructureIngredientRows(string $recipeCode, ?float $batchSizeKg = null): Collection
    {
        $rows = app(WinManConnection::class)
            ->connection()
            ->select(
                "SELECT
                    C.ProductId AS ComponentProductId,
                    C.ProductDescription AS ComponentProductDescription,
                    S.Quantity AS QuantityPerUnit,
                    S.StructureLevel
                FROM Structures AS S
                INNER JOIN Products AS P ON P.Product = S.Product
                INNER JOIN Products AS C ON C.Product = S.Component
                WHERE P.ProductId = ?
                  AND P.Classification = ?
                  AND S.Type = ?
                ORDER BY S.StructureLevel, C.ProductId",
                [$recipeCode, 30, 'C'],
            );

        return collect($rows)
            ->map(static function (object $row) use ($batchSizeKg): array {
                $factor = (float) ($row->QuantityPerUnit ?? 0);

                return [
                    'material_code' => trim((string) ($row->ComponentProductId ?? '')),
                    'description' => trim((string) ($row->ComponentProductDescription ?? '')),
                    'percentage' => $factor > 0 ? $factor * 100 : null,
                    'quantity' => $batchSizeKg !== null ? $factor * $batchSizeKg : $factor,
                    'uom' => 'KG',
                    'sequence' => (int) ($row->StructureLevel ?? 99999),
                ];
            })
            ->values();
    }

    private function resolveDocumentBatchSizeKg(BatchRecord $batch, ?RecipeCard $recipeCard): ?float
    {
        $plannedQuantity = round((float) ($batch->planned_quantity ?? 0), 3);
        if ($plannedQuantity > 0) {
            return $plannedQuantity;
        }

        if ($recipeCard !== null && is_array($recipeCard->batch_sizes_kg)) {
            foreach ($recipeCard->batch_sizes_kg as $size) {
                if (! is_numeric($size)) {
                    continue;
                }

                $numeric = round((float) $size, 3);
                if ($numeric > 0) {
                    return $numeric;
                }
            }
        }

        $legacy = round((float) ($recipeCard?->batch_size_kg ?? 0), 3);

        return $legacy > 0 ? $legacy : null;
    }

    /**
     * @param Collection<int, BatchRecord> $batches
     * @return array<int, array<string, string>>
     */
    private function resolveIssuedLotsByBatchAndMaterial(Collection $batches): array
    {
        $batchIds = $batches->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($batchIds === []) {
            return [];
        }

        $logs = WinManIssueLog::query()
            ->whereIn('batch_record_id', $batchIds)
            ->whereNotNull('material_code')
            ->whereNotNull('lot_number')
            ->orderBy('issue_date')
            ->get();

        $result = [];
        foreach ($logs as $log) {
            $batchId = (int) $log->batch_record_id;
            $material = trim((string) $log->material_code);
            $lot = trim((string) $log->lot_number);

            if ($batchId === 0 || $material === '' || $lot === '') {
                continue;
            }

            $existing = $result[$batchId][$material] ?? '';
            if ($existing === '') {
                $result[$batchId][$material] = $lot;
                continue;
            }

            $existingLots = array_values(array_filter(array_map('trim', explode(', ', $existing)), static fn (string $value): bool => $value !== ''));
            if (! in_array($lot, $existingLots, true)) {
                $existingLots[] = $lot;
                $result[$batchId][$material] = implode(', ', $existingLots);
            }
        }

        return $result;
    }

    /**
     * @return array{code: array<string, array{weighted: bool, tipped: bool}>, description: array<string, array{weighted: bool, tipped: bool}>}
     */
    private function resolveIngredientSignoffsForBatch(BatchRecord $batch): array
    {
        $byCode = [];
        $byDescription = [];

        foreach ($batch->ingredientLots as $lot) {
            $weighted = $lot->weighed_at !== null;
            $tipped = $lot->tipped_at !== null;

            if (! $weighted && ! $tipped) {
                continue;
            }

            $signoff = [
                'weighted' => $weighted,
                'tipped' => $tipped,
            ];

            $materialCode = trim((string) ($lot->material_code ?? ''));
            $description = strtoupper(trim((string) ($lot->material_description ?? '')));

            if ($materialCode !== '') {
                $existing = $byCode[$materialCode] ?? ['weighted' => false, 'tipped' => false];
                $byCode[$materialCode] = [
                    'weighted' => $existing['weighted'] || $signoff['weighted'],
                    'tipped' => $existing['tipped'] || $signoff['tipped'],
                ];
            }

            if ($description !== '') {
                $existing = $byDescription[$description] ?? ['weighted' => false, 'tipped' => false];
                $byDescription[$description] = [
                    'weighted' => $existing['weighted'] || $signoff['weighted'],
                    'tipped' => $existing['tipped'] || $signoff['tipped'],
                ];
            }
        }

        return [
            'code' => $byCode,
            'description' => $byDescription,
        ];
    }

    /**
     * @param  array{code: array<string, array{weighted: bool, tipped: bool}>, description: array<string, array{weighted: bool, tipped: bool}>}  $ingredientSignoffs
     * @return array<int, string>
     */
    private function buildIngredientBatchMarks(string $materialCode, string $description, array $ingredientSignoffs, int $batchColumnIndex): array
    {
        $marks = $ingredientSignoffs['code'][$materialCode] ?? null;

        if ($marks === null) {
            $marks = $ingredientSignoffs['description'][strtoupper(trim($description))] ?? null;
        }

        $cells = array_fill(0, 8, '');
        if ($marks !== null) {
            $parts = [];
            if (($marks['weighted'] ?? false) === true) {
                $parts[] = 'W';
            }
            if (($marks['tipped'] ?? false) === true) {
                $parts[] = 'T';
            }

            if ($parts !== []) {
                $targetIndex = max(0, min(7, $batchColumnIndex - 1));
                $cells[$targetIndex] = implode('|', $parts);
            }
        }

        return $cells;
    }

    /**
     * @return array{powders_weighed_by: string, powders_weighed_date: string, liquids_weighed_by: string, liquids_weighed_date: string, tipping_batch_by: string, tipping_batch_date: string}
     */
    private function resolveIngredientSignoffSummaryForBatch(BatchRecord $batch): array
    {
        $weighedByFallback = $batch->ingredientLots
            ->filter(fn ($lot): bool => $lot->weighed_at !== null)
            ->map(fn ($lot): string => $this->formatOperatorInitials($lot->weighedBy?->name))
            ->filter(fn (string $value): bool => trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        $tippedByFallback = $batch->ingredientLots
            ->filter(fn ($lot): bool => $lot->tipped_at !== null)
            ->map(fn ($lot): string => $this->formatOperatorInitials($lot->tippedBy?->name))
            ->filter(fn (string $value): bool => trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        $paperworkRows = $batch->paperworkRows->keyBy('row_key');

        $powdersRow = $paperworkRows->get('ingredients_signoff.powders_weighed_by');
        $liquidsRow = $paperworkRows->get('ingredients_signoff.liquids_weighed_by');
        $tippingRow = $paperworkRows->get('ingredients_signoff.tipping_batch_by');

        $powdersName = trim((string) ($powdersRow?->value_text ?? ''));
        $liquidsName = trim((string) ($liquidsRow?->value_text ?? ''));
        $tippingName = trim((string) ($tippingRow?->value_text ?? ''));

        $processedDate = $batch->pallecons
            ->sortByDesc('created_at')
            ->first()?->created_at?->format('d/m/Y')
            ?? $batch->production_date?->format('d/m/Y')
            ?? '—';

        $powdersDate = $powdersRow?->completed_at?->format('d/m/Y')
            ?? $processedDate;
        $liquidsDate = $liquidsRow?->completed_at?->format('d/m/Y')
            ?? $processedDate;
        $tippingDate = $tippingRow?->completed_at?->format('d/m/Y')
            ?? $processedDate;

        return [
            'powders_weighed_by' => $powdersName !== ''
                ? $this->formatOperatorInitials($powdersName)
                : ($weighedByFallback === [] ? '—' : implode(', ', $weighedByFallback)),
            'powders_weighed_date' => $powdersDate,
            'liquids_weighed_by' => $liquidsName !== ''
                ? $this->formatOperatorInitials($liquidsName)
                : ($weighedByFallback === [] ? '—' : implode(', ', $weighedByFallback)),
            'liquids_weighed_date' => $liquidsDate,
            'tipping_batch_by' => $tippingName !== ''
                ? $this->formatOperatorInitials($tippingName)
                : ($tippedByFallback === [] ? '—' : implode(', ', $tippedByFallback)),
            'tipping_batch_date' => $tippingDate,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function consolidateSectionsByMo(array $sections): array
    {
        $grouped = [];
        $order = [];

        foreach ($sections as $section) {
            $mo = trim((string) ($section['mo_number'] ?? ''));
            $recipe = trim((string) ($section['recipe_code'] ?? ''));
            $key = ($mo !== '' ? $mo : 'unknown-mo').'|'.($recipe !== '' ? $recipe : 'unknown-recipe').'|'.(int) ($section['sheet_number'] ?? 1)
                .'|'.number_format((float) ($section['batch_size_kg'] ?? 0), 3, '.', '');

            if (! isset($grouped[$key])) {
                $grouped[$key] = $section;
                $order[] = $key;
                continue;
            }

            $existing = $grouped[$key];
            $existing['batch_count'] = (int) ($existing['batch_count'] ?? 1) + 1;
            foreach (['signoff_columns', 'process_columns'] as $group) {
                foreach ((array) ($section[$group] ?? []) as $row => $cells) {
                    $existing[$group][$row] = $this->mergeColumnCells((array) ($existing[$group][$row] ?? []), (array) $cells);
                }
            }
            $existing['label_batch_numbers'] = $this->mergeLabelBatchNumbers(
                is_array($existing['label_batch_numbers'] ?? null) ? $existing['label_batch_numbers'] : array_fill(0, 8, ''),
                is_array($section['label_batch_numbers'] ?? null) ? $section['label_batch_numbers'] : array_fill(0, 8, ''),
            );

            $existing['components'] = $this->mergeSectionComponents(
                is_array($existing['components'] ?? null) ? $existing['components'] : [],
                is_array($section['components'] ?? null) ? $section['components'] : [],
            );

            $grouped[$key] = $existing;
        }

        $result = [];
        foreach ($order as $key) {
            if (isset($grouped[$key])) {
                $result[] = $grouped[$key];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $existing
     * @param  array<int, string>  $incoming
     * @return array<int, string>
     */
    private function mergeLabelBatchNumbers(array $existing, array $incoming): array
    {
        $merged = array_pad(array_slice($existing, 0, 8), 8, '');
        $incoming = array_pad(array_slice($incoming, 0, 8), 8, '');

        for ($i = 0; $i < 8; $i++) {
            $left = trim((string) ($merged[$i] ?? ''));
            $right = trim((string) ($incoming[$i] ?? ''));

            if ($right === '') {
                continue;
            }

            if ($left === '') {
                $merged[$i] = $right;
                continue;
            }

            if ($left !== $right) {
                $merged[$i] = $left.', '.$right;
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeSectionComponents(array $existing, array $incoming): array
    {
        $indexByKey = [];

        foreach ($existing as $idx => $row) {
            $key = $this->componentMergeKey($row);
            $indexByKey[$key] = $idx;
        }

        foreach ($incoming as $row) {
            $key = $this->componentMergeKey($row);
            if (! array_key_exists($key, $indexByKey)) {
                $existing[] = $row;
                $indexByKey[$key] = count($existing) - 1;
                continue;
            }

            $targetIdx = $indexByKey[$key];
            $target = $existing[$targetIdx];

            $target['lot_number'] = $this->mergeLots(
                trim((string) ($target['lot_number'] ?? '')),
                trim((string) ($row['lot_number'] ?? '')),
            );

            $targetMarks = is_array($target['batch_marks'] ?? null) ? $target['batch_marks'] : array_fill(0, 8, '');
            $rowMarks = is_array($row['batch_marks'] ?? null) ? $row['batch_marks'] : array_fill(0, 8, '');
            $target['batch_marks'] = $this->mergeBatchMarks($targetMarks, $rowMarks);

            $existing[$targetIdx] = $target;
        }

        return $existing;
    }

    /** @param array<string, mixed> $row */
    private function componentMergeKey(array $row): string
    {
        return trim((string) ($row['material_code'] ?? '')).'|'.strtoupper(trim((string) ($row['description'] ?? '')));
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     * @return array<int, string>
     */
    private function mergeBatchMarks(array $left, array $right): array
    {
        $result = array_pad(array_slice($left, 0, 8), 8, '');
        $right = array_pad(array_slice($right, 0, 8), 8, '');

        for ($i = 0; $i < 8; $i++) {
            $a = (string) ($result[$i] ?? '');
            $b = (string) ($right[$i] ?? '');

            $hasWeighted = str_contains($a, 'W') || str_contains($b, 'W');
            $hasTipped = str_contains($a, 'T') || str_contains($b, 'T');

            $parts = [];
            if ($hasWeighted) {
                $parts[] = 'W';
            }
            if ($hasTipped) {
                $parts[] = 'T';
            }

            $result[$i] = implode('|', $parts);
        }

        return $result;
    }

    private function mergeLots(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }

        if ($right === '' || $left === $right) {
            return $left;
        }

        $parts = array_values(array_filter(array_map('trim', explode(', ', $left)), static fn (string $value): bool => $value !== ''));
        foreach (array_values(array_filter(array_map('trim', explode(', ', $right)), static fn (string $value): bool => $value !== '')) as $lot) {
            if (! in_array($lot, $parts, true)) {
                $parts[] = $lot;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{mill_gap_size_used: string, p1_speed_used: string, p2_speed_used: string}
     */
    private function resolveProcessSettingsSummaryForBatch(BatchRecord $batch): array
    {
        $paperworkRows = $batch->paperworkRows->keyBy('row_key');

        $millGap = trim((string) ($paperworkRows->get('process_settings.mill_gap_size_used')?->value_text ?? ''));
        $p1Speed = trim((string) ($paperworkRows->get('process_settings.p1_speed_used')?->value_text ?? ''));
        $p2Speed = trim((string) ($paperworkRows->get('process_settings.p2_speed_used')?->value_text ?? ''));

        return [
            'mill_gap_size_used' => $millGap !== '' ? $millGap : '—',
            'p1_speed_used' => $p1Speed !== '' ? $p1Speed : '—',
            'p2_speed_used' => $p2Speed !== '' ? $p2Speed : '—',
        ];
    }

    private function formatOperatorInitials(?string $name): string
    {
        $trimmed = trim((string) $name);
        if ($trimmed === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $trimmed) ?: [];
        $mode = FeatureSettings::value('paperwork.signoff_display_mode', 'short_initials') ?? 'short_initials';

        return match ($mode) {
            'initial_last_name' => $this->formatOperatorInitialLastName($parts, $trimmed),
            'full_initials' => $this->formatOperatorFullInitials($parts, $trimmed),
            default => $this->formatOperatorShortInitials($parts, $trimmed),
        };
    }

    /** @param array<int, string> $parts */
    private function formatOperatorShortInitials(array $parts, string $trimmed): string
    {
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper(Str::substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : strtoupper(Str::substr($trimmed, 0, 2));
    }

    /** @param array<int, string> $parts */
    private function formatOperatorInitialLastName(array $parts, string $trimmed): string
    {
        $parts = array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
        if ($parts === []) {
            return strtoupper(Str::substr($trimmed, 0, 1));
        }

        $firstInitial = strtoupper(Str::substr($parts[0], 0, 1));
        $lastName = $parts[count($parts) - 1];

        return trim($firstInitial.' '.$lastName);
    }

    /** @param array<int, string> $parts */
    private function formatOperatorFullInitials(array $parts, string $trimmed): string
    {
        $initials = collect($parts)
            ->filter()
            ->map(static fn (string $part): string => strtoupper(Str::substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : strtoupper(Str::substr($trimmed, 0, 2));
    }

    /**
     * @param Collection<int, BatchRecord> $batches
     * @return array<int, array<int, string>>
     */
    private function resolveLabelBatchNumbersByBatch(Collection $batches): array
    {
        $result = [];

        foreach ($batches as $batch) {
            $values = $batch->bookingLogs
                ->filter(fn (WinManBookingLog $log): bool =>
                    $log->booking_status === WinManBookingLog::STATUS_SUCCESS
                    && trim((string) ($log->lot_number ?? '')) !== ''
                )
                ->sortBy('booking_date')
                ->map(fn (WinManBookingLog $log): string => trim((string) ($log->lot_number ?? '')))
                ->filter(fn (string $value): bool => $value !== '')
                ->unique()
                ->take(8)
                ->values()
                ->all();

            if ($values === []) {
                $values = $batch->pallecons
                ->sortBy('id')
                ->map(function ($pallecon): string {
                    $labelBatch = trim((string) ($pallecon->liner_batch_code ?? ''));
                    if ($labelBatch !== '') {
                        return $labelBatch;
                    }

                    $serial = trim((string) ($pallecon->serial_number ?? ''));
                    if ($serial !== '') {
                        return $serial;
                    }

                    return trim((string) ($pallecon->ticket_number ?? ''));
                })
                ->filter(fn (string $value): bool => $value !== '')
                ->unique()
                ->take(8)
                ->values()
                ->all();
            }

            $result[(int) $batch->id] = $this->placeInColumn($this->sheetPosition($batch), implode(', ', $values));
        }

        return $result;
    }

    /**
     * Resolve recipe code/description for each batch from Product Mapping using
     * MO winman_product_id (structure product id), including one intermediate hop.
     *
     * @param Collection<int, BatchRecord> $batches
     * @return array<int, array{recipe_code: string, recipe_description: string|null}>
     */
    private function resolveRecipeContextForBatches(Collection $batches): array
    {
        $context = [];

        $structureIds = $batches
            ->map(fn (BatchRecord $batch): string => trim((string) ($batch->manufacturingOrder?->winman_product_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($structureIds === []) {
            return $context;
        }

        $initialRows = ProductMapping::query()
            ->whereIn('structure_product_id', $structureIds)
            ->get();

        if ($initialRows->isEmpty()) {
            return $context;
        }

        $intermediateIds = $initialRows
            ->map(fn (ProductMapping $row): string => trim((string) ($row->component_product_id ?? '')))
            ->filter(fn (string $id): bool => $this->isIntermediateProductId($id))
            ->unique()
            ->values()
            ->all();

        $secondHopRows = $intermediateIds !== []
            ? ProductMapping::query()->whereIn('structure_product_id', $intermediateIds)->get()
            : collect();

        $rowsByStructure = $initialRows
            ->concat($secondHopRows)
            ->groupBy(fn (ProductMapping $row): string => trim((string) $row->structure_product_id));

        foreach ($batches as $batch) {
            $batchId = (int) $batch->id;
            $structureId = trim((string) ($batch->manufacturingOrder?->winman_product_id ?? ''));

            if ($structureId === '') {
                continue;
            }

            $group = collect($rowsByStructure->get($structureId, []));
            if ($group->isEmpty()) {
                continue;
            }

            $directRecipe = $group->first(fn (ProductMapping $row): bool => $this->isRecipeProductId(trim((string) $row->component_product_id)));
            $resolvedRecipe = $directRecipe;

            if ($resolvedRecipe === null) {
                $intermediate = $group->first(fn (ProductMapping $row): bool => $this->isIntermediateProductId(trim((string) $row->component_product_id)));
                if ($intermediate !== null) {
                    $secondGroup = collect($rowsByStructure->get(trim((string) $intermediate->component_product_id), []));
                    $resolvedRecipe = $secondGroup->first(fn (ProductMapping $row): bool => $this->isRecipeProductId(trim((string) $row->component_product_id)));
                }
            }

            if ($resolvedRecipe === null) {
                continue;
            }

            $context[$batchId] = [
                'recipe_code' => trim((string) $resolvedRecipe->component_product_id),
                'recipe_description' => trim((string) ($resolvedRecipe->component_product_description ?? '')),
            ];
        }

        return $context;
    }

    private function isRecipeProductId(string $productId): bool
    {
        return str_starts_with($productId, '3001') || str_starts_with($productId, '9900');
    }

    private function isIntermediateProductId(string $productId): bool
    {
        return str_starts_with($productId, '5001');
    }
}
