<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Reporting\Contracts\SplitsIntoRuns;
use App\Domains\Reporting\Support\BatchCardBuilder;
use App\Domains\Reporting\Support\DocumentSetup;
use App\Domains\Reporting\Support\DocumentSources;
use App\Models\BatchRecord;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\ManufacturingOrder;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A recipe's manufacturing batch card (Settings > Documents, "Recipe driven"):
 * one sheet per MO with its batches as columns, laid out by the document's
 * setup. Scheduled as "doc_{code}" and sent as one email per MO run
 * ("doc_{code}@{MO}"). Covers wet bulk manufacturing MOs (WinMan
 * classification 30) only - pallecon intermediates have their own report.
 */
class RecipeBatchCardReport extends AbstractReport implements SplitsIntoRuns
{
    public const RUN_SEPARATOR = '@';

    public const MANUFACTURING_CLASSIFICATION = 30;

    public function __construct(
        private readonly DocumentReference $document,
        private readonly string $recipeCode,
        private readonly ?string $moReference = null,
    ) {
    }

    public function key(): string
    {
        return 'doc_'.$this->document->code.($this->moReference !== null ? self::RUN_SEPARATOR.$this->moReference : '');
    }

    public function name(): string
    {
        return trim($this->document->code.' - '.$this->document->title)
            .($this->moReference !== null ? ' · '.$this->moReference : '');
    }

    public function runKeys(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->batchesInPeriod($from, $to)
            ->map(fn (BatchRecord $batch): string => $this->moReferenceFor($batch->manufacturingOrder))
            ->filter()
            ->unique()
            ->values()
            ->map(fn (string $mo): string => 'doc_'.$this->document->code.self::RUN_SEPARATOR.$mo)
            ->all();
    }

    public function isRun(): bool
    {
        return $this->moReference !== null;
    }

    public function generate(CarbonInterface $from, CarbonInterface $to): array
    {
        $batches = $this->batches($from, $to);
        $data = $this->summarise($batches);

        $payload = [
            'subject' => $this->moReference !== null
                ? sprintf('DBMTS · %s batch card', $this->name())
                : sprintf('DBMTS · %s (%s to %s)', $this->name(), $from->toDateString(), $to->toDateString()),
            'html' => $this->render($from, $to, $data['headers'], $data['rows'], $data['summary']),
            'row_count' => count($data['rows']),
            'skip_if_empty' => true,
        ];

        if ($batches->isNotEmpty()) {
            $attachment = $this->buildAttachment($batches);
            if ($attachment !== null) {
                $payload['attachments'] = [$attachment];
            }
        }

        return $payload;
    }

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->summarise($this->batches($from, $to));
    }

    /**
     * Sheets for the given batches, with the header's document control taken
     * from this document (not the recipe card).
     *
     * @param  Collection<int, BatchRecord>  $batches
     * @return array<int, array<string, mixed>>
     */
    public function sections(Collection $batches): array
    {
        $control = $this->documentControl();
        $code = strtoupper(trim((string) $this->document->code));

        return array_map(function (array $section) use ($control, $code): array {
            $recipeLine = trim(($section['recipe_code'] !== '—' ? $section['recipe_code'].' ' : '').$section['product_description']);
            $section['header_reference_line'] = strtoupper(trim($code.' - '.$recipeLine, ' -'));
            $section['document_reference'] = $code;

            return array_merge($section, $control);
        }, app(BatchCardBuilder::class)->sections($batches, null));
    }

    /** @return array<string, mixed> */
    public function setup(): array
    {
        return app(DocumentSetup::class)->resolveForDocument($this->document, $this->document->code, DocumentSources::RECIPE_BATCH_CARD);
    }

    /** @return Collection<int, BatchRecord> */
    private function batches(CarbonInterface $from, CarbonInterface $to): Collection
    {
        if ($this->moReference === null) {
            return $this->batchesInPeriod($from, $to);
        }

        // A run's sheet shows every batch of the MO, whichever day it was made.
        $order = ManufacturingOrder::query()
            ->where('winman_manufacturing_order_id', $this->moReference)
            ->orWhere('mo_number', $this->moReference)
            ->first();

        if ($order === null) {
            return collect();
        }

        return $this->forThisRecipe(
            $this->manufacturingBatchQuery()->where('manufacturing_order_id', $order->id)->get()
        );
    }

    /** @return Collection<int, BatchRecord> */
    private function batchesInPeriod(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $batches = $this->manufacturingBatchQuery()
            ->where(function ($query) use ($fromDate, $toDate): void {
                $query->where(fn ($dated) => $dated->whereDate('production_date', '>=', $fromDate)->whereDate('production_date', '<=', $toDate))
                    ->orWhere(fn ($undated) => $undated->whereNull('production_date')->whereDate('created_at', '>=', $fromDate)->whereDate('created_at', '<=', $toDate));
            })
            ->get();

        return $this->forThisRecipe($batches);
    }

    private function manufacturingBatchQuery()
    {
        return BatchRecord::query()
            ->with(BatchCardBuilder::BATCH_RELATIONS)
            ->where('status', '!=', BatchRecord::STATUS_CANCELLED)
            ->whereHas('manufacturingOrder', fn ($query) => $query->where('winman_classification', self::MANUFACTURING_CLASSIFICATION))
            ->orderBy('production_date')
            ->orderBy('batch_number');
    }

    /**
     * Batches of this recipe, excluding pallecon intermediates (reported separately).
     *
     * @param  Collection<int, BatchRecord>  $batches
     * @return Collection<int, BatchRecord>
     */
    private function forThisRecipe(Collection $batches): Collection
    {
        $batches = $batches->reject(fn (BatchRecord $batch): bool => $this->isPalleconMode($batch))->values();
        $recipeCodes = app(BatchCardBuilder::class)->recipeCodesByBatch($batches);

        return $batches
            ->filter(fn (BatchRecord $batch): bool => strcasecmp($recipeCodes[(int) $batch->id] ?? '', $this->recipeCode) === 0)
            ->values();
    }

    /** Same rule DailyIntermediateProductionReport uses to pick pallecon batches. */
    private function isPalleconMode(BatchRecord $batch): bool
    {
        $mo = $batch->manufacturingOrder;
        $uomDescription = strtoupper(trim((string) ($mo?->winman_unit_of_measure_description ?? '')));

        return (int) ($mo?->winman_unit_of_measure ?? 0) === 2
            || str_contains($uomDescription, 'PALLECON')
            || $batch->pallecons->count() > 0;
    }

    private function moReferenceFor(?ManufacturingOrder $order): string
    {
        return trim((string) ($order?->winman_manufacturing_order_id ?? $order?->mo_number ?? ''));
    }

    /**
     * @param  Collection<int, BatchRecord>  $batches
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, summary: string}
     */
    private function summarise(Collection $batches): array
    {
        $rows = $batches->map(fn (BatchRecord $batch): array => [
            $this->moReferenceFor($batch->manufacturingOrder) ?: '—',
            (string) $batch->batch_number,
            $batch->production_date?->toDateString() ?? $batch->created_at?->toDateString() ?? '—',
            Str::headline((string) $batch->status),
        ])->all();

        return [
            'headers' => ['MO', 'Batch', 'Production date', 'Status'],
            'rows' => $rows,
            'summary' => count($rows).' batch(es) of recipe '.$this->recipeCode.'. The batch card is attached.',
        ];
    }

    /** @return array{revision_no: string, issue_date: string, reason_for_issue: string} */
    private function documentControl(): array
    {
        $latest = Schema::hasTable('document_reference_changes')
            ? DocumentReferenceChange::query()
                ->where('document_reference_id', $this->document->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->first()
            : null;

        return [
            'revision_no' => (string) ($latest?->issue_version ?: ($this->document->version ?? '—')),
            'issue_date' => $latest?->date_issued?->format('d/m/Y') ?? $this->document->issue_date?->format('d/m/Y') ?? '—',
            'reason_for_issue' => (string) ($latest?->reason_for_change ?: '—'),
        ];
    }

    /**
     * @param  Collection<int, BatchRecord>  $batches
     * @return array{path: string, name: string}|null
     */
    private function buildAttachment(Collection $batches): ?array
    {
        $setup = $this->setup();

        $html = (string) view('reports.recipe-batch-card', [
            'setup' => $setup,
            'columns' => collect($setup['columns'] ?? [])->filter(fn ($column): bool => (bool) ($column['visible'] ?? false))->values()->all(),
            'sections' => $this->sections($batches),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper((string) ($setup['paper_size'] ?? 'A4'), (string) ($setup['orientation'] ?? 'landscape'));
        $pdf->render();

        $dir = storage_path('app/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = sprintf(
            '%s-%s-%s.pdf',
            strtolower((string) $this->document->code),
            Str::slug($this->moReference ?? $batches->first()?->production_date?->toDateString() ?? 'batch-card'),
            now()->format('His'),
        );

        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $pdf->output());

        return ['path' => $path, 'name' => $filename];
    }
}
