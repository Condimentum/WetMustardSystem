<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Reporting\Support\DocumentSetup;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\Wm003IbcTraceabilityEntry;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Schema;

class Wm003IbcTraceabilityReport extends AbstractReport
{
    public const KEY = 'wm003_ibc_traceability';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'WM003 Vinegar IBC Traceability (PDF)';
    }

    protected function data(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->queryRows($from, $to)
            ->map(fn (Wm003IbcTraceabilityEntry $row): array => [
                $row->date_used?->toDateString() ?? '—',
                $row->supplier_production_date?->toDateString() ?? '—',
                $row->best_before_date?->toDateString() ?? '—',
                $row->batch_no ?? '—',
                $row->time_on ? \Illuminate\Support\Carbon::parse($row->time_on)->format('H:i') : '—',
                $row->operator_name,
            ])
            ->all();

        return [
            'headers' => ['Date Used', 'Supplier Production Date', 'Best Before Date', 'Batch No.', 'Time On', 'Operator Name'],
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

        $document = DocumentReference::query()->where('code', 'WM003')->first();
        $setup = app(DocumentSetup::class)->resolveForDocument($document, 'WM003');
        $changes = collect();
        if ($document !== null && Schema::hasTable('document_reference_changes')) {
            $changes = DocumentReferenceChange::query()
                ->where('document_reference_id', $document->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->get();
        }

        $html = (string) view('reports.quality.wm003-ibc-traceability', [
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

        $filename = sprintf('wm003-ibc-traceability-%s_to_%s-%s.pdf', $from->toDateString(), $to->toDateString(), now()->format('His'));
        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, $pdf->output());

        return ['path' => $path, 'name' => $filename];
    }

    private function queryRows(CarbonInterface $from, CarbonInterface $to)
    {
        return Wm003IbcTraceabilityEntry::query()
            ->whereDate('date_used', '>=', $from->toDateString())
            ->whereDate('date_used', '<=', $to->toDateString())
            ->orderBy('date_used')
            ->orderBy('id')
            ->get();
    }
}
