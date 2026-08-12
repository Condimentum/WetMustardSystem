<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\WinMan\Support\WinManConnection;
use App\Models\BatchRecord;
use App\Models\PaperworkRow;
use App\Models\ProductMapping;
use App\Models\Recipe;
use App\Models\RecipeCard;
use App\Models\RecipeIngredient;
use App\Models\WinManBookingLog;
use App\Models\WinManIssueLog;
use App\Support\FeatureSettings;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Daily intermediate production summary focused on pallecon workflows
 * (report key: dbmts_daily_intermediate_production).
 */
class DailyIntermediateProductionReport extends AbstractReport
{
    public const KEY = 'dbmts_daily_intermediate_production';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Daily Intermediate Production';
    }

    public function generate(CarbonInterface $from, CarbonInterface $to): array
    {
        $batches = $this->collectIntermediateBatches($from, $to);
        $rows = $this->buildRows($batches);

        $payload = [
            'subject' => sprintf('DBMTS · %s (%s to %s)', $this->name(), $from->toDateString(), $to->toDateString()),
            'html' => $this->render(
                $from,
                $to,
                ['Date', 'Batch', 'MO', 'Product', 'UOM', 'Pallecons', 'Total Fill Kg', 'Planned Kg', 'Status'],
                $rows,
                count($rows).' intermediate batch(es) in pallecon mode for period.',
            ),
            'row_count' => count($rows),
        ];

        $attachment = $this->buildProductionDocumentAttachment($batches, $from, $to);
        if ($attachment !== null) {
            $payload['attachments'] = [$attachment];
        }

        return $payload;
    }

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        $batches = $this->collectIntermediateBatches($from, $to);
        $rows = $this->buildRows($batches);

        return [
            'headers' => ['Date', 'Batch', 'MO', 'Product', 'UOM', 'Pallecons', 'Total Fill Kg', 'Planned Kg', 'Status'],
            'rows' => $rows,
            'summary' => count($rows).' intermediate batch(es) in pallecon mode for period.',
        ];
    }

    /** @return Collection<int, BatchRecord> */
    private function collectIntermediateBatches(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        return BatchRecord::query()
            ->with(['product', 'manufacturingOrder', 'pallecons', 'componentSnapshots', 'ingredientLots.weighedBy', 'ingredientLots.tippedBy', 'paperworkRows', 'bookingLogs'])
            ->orderBy('production_date')
            ->orderBy('batch_number')
            ->get()
            ->filter(function (BatchRecord $batch) use ($fromDate, $toDate): bool {
                $reportDate = $batch->production_date?->toDateString() ?? $batch->created_at?->toDateString();
                if ($reportDate === null) {
                    return false;
                }

                if ($reportDate < $fromDate || $reportDate > $toDate) {
                    return false;
                }

                $mo = $batch->manufacturingOrder;
                if ($mo === null || (int) ($mo->winman_classification ?? 0) !== 30) {
                    return false;
                }

                $uomCode = (int) ($mo->winman_unit_of_measure ?? 0);
                $uomDescription = strtoupper(trim((string) ($mo->winman_unit_of_measure_description ?? '')));

                return $uomCode === 2
                    || str_contains($uomDescription, 'PALLECON')
                    || $batch->pallecons->count() > 0;
            })
            ->values();
    }

    /** @param Collection<int, BatchRecord> $batches
     *  @return array<int, array<int, string>>
     */
    private function buildRows(Collection $batches): array
    {
        return $batches->map(function (BatchRecord $batch): array {
            $mo = $batch->manufacturingOrder;

            return [
                $batch->production_date?->toDateString() ?? $batch->created_at?->toDateString() ?? '—',
                $batch->batch_number,
                $mo?->winman_manufacturing_order_id ?? $mo?->mo_number ?? '—',
                $batch->product?->product_name ?? '—',
                (string) ($mo?->winman_unit_of_measure_description ?? $mo?->winman_unit_of_measure ?? '—'),
                (string) $batch->pallecons->count(),
                number_format((float) $batch->pallecons->sum(fn ($pallecon): float => (float) ($pallecon->fill_weight ?? 0)), 3, '.', ''),
                number_format((float) ($batch->planned_quantity ?? 0), 3, '.', ''),
                Str::headline((string) $batch->status),
            ];
        })->all();
    }

    /** @param Collection<int, BatchRecord> $batches
     *  @return array{path: string, name: string}|null
     */
    private function buildProductionDocumentAttachment(Collection $batches, CarbonInterface $from, CarbonInterface $to): ?array
    {
        $recipeContextByBatchId = $this->resolveRecipeContextForBatches($batches);

        $issueLotsByBatchAndMaterial = $this->resolveIssuedLotsByBatchAndMaterial($batches);
        $labelBatchNumbersByBatch = $this->resolveLabelBatchNumbersByBatch($batches);

        $recipeCodes = $batches
            ->map(function (BatchRecord $batch) use ($recipeContextByBatchId): string {
                $resolved = $recipeContextByBatchId[$batch->id]['recipe_code'] ?? null;

                return trim((string) ($resolved ?? $batch->manufacturingOrder?->recipe_code ?? ''));
            })
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
        );
        $sections = $this->consolidateSectionsByMo($sections);
        $documentHtml = (string) view('reports.daily-intermediate-production-sheet', [
            'from' => $from,
            'to' => $to,
            'sections' => $sections,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($documentHtml);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $pdfBinary = $pdf->output();

        $dir = storage_path('app/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = sprintf(
            'daily-intermediate-production-sheet-%s_to_%s-%s.pdf',
            $from->toDateString(),
            $to->toDateString(),
            now()->format('His'),
        );

        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $pdfBinary);

        return [
            'path' => $path,
            'name' => $filename,
        ];
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
    ): array
    {
        return $batches->map(function (BatchRecord $batch) use ($recipeCards, $recipeContextByBatchId, $issueLotsByBatchAndMaterial, $labelBatchNumbersByBatch): array {
            $mo = $batch->manufacturingOrder;
            $resolvedRecipeCode = trim((string) ($recipeContextByBatchId[$batch->id]['recipe_code'] ?? ''));
            $recipeCode = $resolvedRecipeCode !== ''
                ? $resolvedRecipeCode
                : trim((string) ($mo?->recipe_code ?? ''));
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
            $batchColumnIndex = max(1, (int) ($batch->paperworkRows->first()?->batch_column_index ?? 1));

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
                ->take(9)
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
                'mo_number' => (string) ($mo?->winman_manufacturing_order_id ?? $mo?->mo_number ?? '—'),
                'components' => $componentRows,
                'merge_allergen_material' => false,
                'label_batch_numbers' => $labelBatchNumbersByBatch[(int) $batch->id] ?? array_fill(0, 8, ''),
                'quantity_total' => number_format($totalQty, 3, '.', ''),
                'percent_total' => number_format((float) collect($componentRows)->sum(fn (array $r): float => (float) ($r['percent'] ?? 0)), 2, '.', ''),
                'powders_weighed_by' => $ingredientSignoffSummary['powders_weighed_by'],
                'liquids_weighed_by' => $ingredientSignoffSummary['liquids_weighed_by'],
                'tipping_batch_by' => $ingredientSignoffSummary['tipping_batch_by'],
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
            $key = ($mo !== '' ? $mo : 'unknown-mo').'|'.($recipe !== '' ? $recipe : 'unknown-recipe');

            if (! isset($grouped[$key])) {
                $grouped[$key] = $section;
                $order[] = $key;
                continue;
            }

            $existing = $grouped[$key];
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

            $label = implode(', ', $values);
            $cells = array_fill(0, 8, '');
            $batchColumnIndex = max(1, (int) ($batch->paperworkRows->first()?->batch_column_index ?? 1));
            $targetIndex = max(0, min(7, $batchColumnIndex - 1));
            $cells[$targetIndex] = $label;

            $result[(int) $batch->id] = $cells;
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
