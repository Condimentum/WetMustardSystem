<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Reporting\Support\DocumentSetup;
use App\Domains\Reporting\Support\DocumentSources;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Schema;

/**
 * A check sheet whose columns, widths, order and page layout come from the
 * linked document's setup in Settings > Documents, with cell values resolved
 * through the DocumentSources field registry.
 */
abstract class CalibrationSheetReport extends AbstractReport
{
    private bool $documentResolved = false;

    private ?DocumentReference $resolvedDocument = null;

    /** @var array<string, mixed>|null */
    private ?array $resolvedSetup = null;

    /** Code used when no document is linked to this sheet. */
    abstract protected function documentCode(): string;

    /** Title used when no document is linked to this sheet. */
    abstract protected function sheetTitle(): string;

    /** DocumentSources key (a program key or DocumentSources::MATERIAL_TRIGGER). */
    abstract protected function sourceKey(): string;

    /**
     * Records for the period, one per sheet row.
     *
     * @return iterable<int, mixed>
     */
    abstract protected function sheetRecords(CarbonInterface $from, CarbonInterface $to): iterable;

    /**
     * Shown in the CCP block when the document setup has no CCP message.
     *
     * @return array<int, string>
     */
    protected function sheetInstructions(): array
    {
        return app(DocumentSources::class)->defaultInstructions($this->sourceKey());
    }

    protected function resolveDocument(): ?DocumentReference
    {
        return app(DocumentSources::class)->documentForProgram($this->sourceKey());
    }

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->sheetRows($from, $to);

        return [
            'headers' => array_column($this->sheetColumns(), 'label'),
            'rows' => $rows,
            'summary' => count($rows).' row(s) recorded.',
        ];
    }

    public function generate(CarbonInterface $from, CarbonInterface $to): array
    {
        $payload = parent::generate($from, $to);

        $attachment = $this->buildSheetAttachment($from, $to);
        if ($attachment !== null) {
            $payload['attachments'] = [$attachment];
        }

        return $payload;
    }

    /**
     * Rows of cell strings, in visible column order.
     *
     * @return array<int, array<int, string>>
     */
    protected function sheetRows(CarbonInterface $from, CarbonInterface $to): array
    {
        $fields = collect(app(DocumentSources::class)->fields($this->sourceKey()))->keyBy('key');
        $columns = $this->sheetColumns();

        $rows = [];
        foreach ($this->sheetRecords($from, $to) as $record) {
            $rows[] = array_map(function (array $column) use ($fields, $record): string {
                $resolver = $fields->get($column['key'])['value'] ?? null;

                return $resolver !== null ? $resolver($record) : '—';
            }, $columns);
        }

        return $rows;
    }

    /**
     * Visible columns from the document setup, falling back to the source's defaults.
     *
     * @return array<int, array{key: string, label: string, width: float}>
     */
    protected function sheetColumns(): array
    {
        $visible = collect($this->setup()['columns'] ?? [])
            ->filter(fn ($column): bool => is_array($column) && (bool) ($column['visible'] ?? false));

        if ($visible->isEmpty()) {
            $visible = collect(app(DocumentSources::class)->defaultColumns($this->sourceKey()))
                ->filter(fn (array $column): bool => $column['visible']);
        }

        return $visible
            ->map(fn (array $column): array => [
                'key' => (string) $column['key'],
                'label' => (string) $column['label'],
                'width' => (float) $column['width'],
            ])
            ->values()
            ->all();
    }

    protected function document(): ?DocumentReference
    {
        if (! $this->documentResolved) {
            $this->resolvedDocument = $this->resolveDocument();
            $this->documentResolved = true;
        }

        return $this->resolvedDocument;
    }

    /** @return array<string, mixed> */
    protected function setup(): array
    {
        return $this->resolvedSetup ??= app(DocumentSetup::class)
            ->resolveForDocument($this->document(), $this->documentCode(), $this->sourceKey());
    }

    /**
     * @return array{path: string, name: string}|null
     */
    private function buildSheetAttachment(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $document = $this->document();

        $changes = collect();
        if ($document !== null && Schema::hasTable('document_reference_changes')) {
            $changes = DocumentReferenceChange::query()
                ->where('document_reference_id', $document->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->get();
        }

        $setup = $this->setup();
        if (trim((string) ($setup['ccp_message'] ?? '')) === '') {
            $setup['ccp_message'] = implode("\n", $this->sheetInstructions());
        }

        $html = (string) view('reports.document-sheet', [
            'setup' => $setup,
            'document' => $document,
            'changes' => $changes,
            'title' => $document !== null ? trim($document->code.' - '.$document->title) : $this->sheetTitle(),
            'columns' => $this->sheetColumns(),
            'rows' => $this->sheetRows($from, $to),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper((string) ($setup['paper_size'] ?? 'A4'), (string) ($setup['orientation'] ?? 'portrait'));
        $pdf->render();

        $dir = storage_path('app/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = sprintf(
            '%s-%s_to_%s-%s.pdf',
            strtolower($document?->code ?? $this->documentCode()),
            $from->toDateString(),
            $to->toDateString(),
            now()->format('His'),
        );

        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $pdf->output());

        return [
            'path' => $path,
            'name' => $filename,
        ];
    }
}
