<?php

namespace App\Domains\Reporting\Reports;

use App\Models\DocumentReference;
use App\Models\MetalDetectorCheck;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Schema;

class MetalDetectorVerificationSheetReport extends AbstractReport
{
    public const KEY = 'metal_detector_verification_sheet';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Metal Detector Verification Sheet (PDF)';
    }

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->checksQuery($from, $to)
            ->get()
            ->map(fn (MetalDetectorCheck $check): array => [
                $check->check_time?->format('Y-m-d') ?? '—',
                $check->check_time?->format('H:i:s') ?? '—',
                $check->fe10_pass ? 'Pass' : 'Fail',
                $check->non_fe15_pass ? 'Pass' : 'Fail',
                $check->ss20_pass ? 'Pass' : 'Fail',
                $check->signedBy?->name ?? '—',
            ])
            ->all();

        return [
            'headers' => ['Date', 'Time', 'Ferrous 1.0', 'Non-Ferrous 1.5', 'SS 2.0', 'Operator'],
            'rows' => $rows,
            'summary' => count($rows).' check(s) recorded.',
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
        $checks = $this->checksQuery($from, $to)
            ->orderBy('check_time')
            ->get();

        $document = DocumentReference::query()
            ->where('module', 'like', '%metal%')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->first();

        $documentChanges = collect();
        if ($document !== null && Schema::hasTable('document_reference_changes')) {
            $documentChanges = $document->changes()
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->limit(15)
                ->get();
        }

        $html = (string) view('reports.metal-detector-verification-sheet', [
            'forDate' => $to,
            'checks' => $checks,
            'document' => $document,
            'documentChanges' => $documentChanges,
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        $dir = storage_path('app/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $filename = sprintf(
            'metal-detector-verification-%s_to_%s-%s.pdf',
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

    private function checksQuery(CarbonInterface $from, CarbonInterface $to)
    {
        return MetalDetectorCheck::query()
            ->with('signedBy')
            ->whereNull('batch_record_id')
            ->whereDate('check_time', '>=', $from->toDateString())
            ->whereDate('check_time', '<=', $to->toDateString());
    }
}
