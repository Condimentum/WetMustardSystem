<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Reporting\Support\DocumentSetup;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\Wm010RinseWaterTestEntry;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Schema;

class Wm010RinseWaterTestReport extends AbstractReport
{
    public const KEY = 'wm010_rinse_water_test';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'WM010 Rinse Water Test Sheet (PDF)';
    }

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->queryRows($from, $to)
            ->map(fn (Wm010RinseWaterTestEntry $row): array => [
                $row->tested_date?->toDateString() ?? '—',
                \Illuminate\Support\Str::headline((string) $row->section),
                $row->equipment ?? '—',
                $row->reading !== null ? $row->reading.' '.($row->reading_unit ?? '') : '—',
                $row->pass_or_fail ?? '—',
                $row->operator_name ?? '—',
            ])
            ->all();

        return [
            'headers' => ['Date', 'Section', 'Equipment', 'Reading', 'Pass/Fail', 'Operator'],
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

        $document = DocumentReference::query()->where('code', 'WM010')->first();
        $setup = app(DocumentSetup::class)->resolveForDocument($document, 'WM010');
        $changes = collect();
        if ($document !== null && Schema::hasTable('document_reference_changes')) {
            $changes = DocumentReferenceChange::query()
                ->where('document_reference_id', $document->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->get();
        }

        $html = (string) view('reports.quality.wm010-rinse-water-test', [
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
        $pdf->setPaper((string) ($setup['paper_size'] ?? 'A4'), (string) ($setup['orientation'] ?? 'portrait'));
        $pdf->render();

        $dir = storage_path('app/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = sprintf('wm010-rinse-water-test-%s_to_%s-%s.pdf', $from->toDateString(), $to->toDateString(), now()->format('His'));
        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $pdf->output());

        return ['path' => $path, 'name' => $filename];
    }

    private function queryRows(CarbonInterface $from, CarbonInterface $to)
    {
        return Wm010RinseWaterTestEntry::query()
            ->whereDate('tested_date', '>=', $from->toDateString())
            ->whereDate('tested_date', '<=', $to->toDateString())
            ->orderBy('tested_date')
            ->orderBy('id')
            ->get();
    }
}
