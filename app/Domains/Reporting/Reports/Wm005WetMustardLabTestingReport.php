<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Reporting\Support\DocumentSetup;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\Wm005LabTestingEntry;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Schema;

class Wm005WetMustardLabTestingReport extends AbstractReport
{
    public const KEY = 'wm005_wet_mustard_lab_testing';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'WM005 Wet Mustard Lab Testing (PDF)';
    }

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->queryRows($from, $to)
            ->map(fn (Wm005LabTestingEntry $row): array => [
                $row->tested_date?->toDateString() ?? '—',
                $row->tested_time ? \Illuminate\Support\Carbon::parse($row->tested_time)->format('H:i') : '—',
                $row->mo_number ?? '—',
                $row->batch_number ?? '—',
                (string) ($row->ph ?? '—'),
                (string) ($row->salt ?? '—'),
                $row->tested_by,
            ])
            ->all();

        return [
            'headers' => ['Date', 'Time', 'MO Number', 'Batch Number', 'pH', 'Salt', 'Tested By'],
            'rows' => $rows,
            'summary' => count($rows).' row(s) recorded.',
        ];
    }

    public function generate(CarbonInterface $from, CarbonInterface $to): array
    {
        $payload = parent::generate($from, $to);

        $attachment = $this->buildAttachment($from, $to);
        if ($attachment !== null) {
            $payload['attachments'] = [$attachment];
        }

        return $payload;
    }

    /** @return array{path: string, name: string}|null */
    private function buildAttachment(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $rows = $this->queryRows($from, $to)->all();

        $document = DocumentReference::query()->where('code', 'WM005')->first();
        $setup = app(DocumentSetup::class)->resolveForDocument($document, 'WM005');
        $changes = collect();
        if ($document !== null && Schema::hasTable('document_reference_changes')) {
            $changes = DocumentReferenceChange::query()
                ->where('document_reference_id', $document->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->get();
        }

        $html = (string) view('reports.quality.wm005-wet-mustard-lab-testing', [
            'rows' => $rows,
            'document' => $document,
            'changes' => $changes,
            'setup' => $setup,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper((string) ($setup['paper_size'] ?? 'A4'), (string) ($setup['orientation'] ?? 'landscape'));
        $pdf->render();

        $dir = storage_path('app/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = sprintf('wm005-wet-mustard-lab-testing-%s_to_%s-%s.pdf', $from->toDateString(), $to->toDateString(), now()->format('His'));
        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $pdf->output());

        return ['path' => $path, 'name' => $filename];
    }

    private function queryRows(CarbonInterface $from, CarbonInterface $to)
    {
        return Wm005LabTestingEntry::query()
            ->whereDate('tested_date', '>=', $from->toDateString())
            ->whereDate('tested_date', '<=', $to->toDateString())
            ->orderBy('tested_date')
            ->orderBy('id')
            ->get();
    }
}
