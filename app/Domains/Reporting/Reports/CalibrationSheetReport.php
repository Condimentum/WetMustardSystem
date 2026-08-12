<?php

namespace App\Domains\Reporting\Reports;

use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Schema;

abstract class CalibrationSheetReport extends AbstractReport
{
    abstract protected function documentCode(): string;

    abstract protected function sheetTitle(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function sheetHeaders(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function sheetInstructions(): array;

    /**
     * @return array<int, array<int, string>>
     */
    abstract protected function sheetRows(CarbonInterface $from, CarbonInterface $to): array;

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->sheetRows($from, $to);

        return [
            'headers' => $this->sheetHeaders(),
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
     * @return array{path: string, name: string}|null
     */
    private function buildSheetAttachment(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $document = DocumentReference::query()
            ->where('code', $this->documentCode())
            ->first();

        $changes = collect();
        if ($document !== null && Schema::hasTable('document_reference_changes')) {
            $changes = DocumentReferenceChange::query()
                ->where('document_reference_id', $document->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->get();
        }

        $html = (string) view('reports.calibrations.check-sheet', [
            'document' => $document,
            'changes' => $changes,
            'documentCode' => $this->documentCode(),
            'title' => $this->sheetTitle(),
            'from' => $from,
            'to' => $to,
            'instructions' => $this->sheetInstructions(),
            'headers' => $this->sheetHeaders(),
            'rows' => $this->sheetRows($from, $to),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        $dir = storage_path('app/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = sprintf(
            '%s-%s_to_%s-%s.pdf',
            strtolower($this->documentCode()),
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
