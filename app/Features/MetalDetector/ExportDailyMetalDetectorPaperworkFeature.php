<?php

namespace App\Features\MetalDetector;

use App\Models\DocumentReference;
use App\Models\MetalDetectorCheck;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class ExportDailyMetalDetectorPaperworkFeature
{
    public function __invoke(?string $date = null): Response
    {
        $forDate = $this->resolveDate($date);

        $checks = MetalDetectorCheck::query()
            ->with('signedBy')
            ->whereNull('batch_record_id')
            ->whereDate('check_time', $forDate->toDateString())
            ->orderBy('check_time')
            ->get();

        $document = DocumentReference::query()
            ->where('module', 'like', '%metal%')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->first();

        $documentChanges = collect();
        if ($document && Schema::hasTable('document_reference_changes')) {
            $documentChanges = $document->changes()
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->limit(15)
                ->get();
        }

        $html = (string) view('reports.metal-detector-verification-sheet', [
            'forDate' => $forDate,
            'checks' => $checks,
            'document' => $document,
            'documentChanges' => $documentChanges,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        $filename = 'metal-detector-verification-'.$forDate->format('Y-m-d').'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function resolveDate(?string $input): Carbon
    {
        if (! is_string($input) || trim($input) === '') {
            return now()->startOfDay();
        }

        try {
            return Carbon::parse($input)->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }
}
