<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Reporting\Support\BatchCardBuilder;
use App\Models\BatchRecord;
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

        if ($rows !== []) {
            $attachment = $this->buildProductionDocumentAttachment($batches, $from, $to);
            if ($attachment !== null) {
                $payload['attachments'] = [$attachment];
            }
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
            ->with(BatchCardBuilder::BATCH_RELATIONS)
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
        $sections = app(BatchCardBuilder::class)->sections($batches);
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
}
