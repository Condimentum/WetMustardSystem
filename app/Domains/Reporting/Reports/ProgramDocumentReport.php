<?php

namespace App\Domains\Reporting\Reports;

use App\Domains\Reporting\Support\DocumentSources;
use App\Models\DocumentReference;
use Carbon\CarbonInterface;

/**
 * Generates a document linked (Settings > Documents) to a table-backed
 * DocumentSources program, e.g. metal detector checks. Resolved at runtime by
 * ReportRegistry for keys of the form "doc_{code}", so new programs need only
 * a DocumentSources entry - no per-program report class.
 */
class ProgramDocumentReport extends CalibrationSheetReport
{
    public function __construct(
        private readonly DocumentReference $document,
        private readonly string $programKey,
    ) {
    }

    public function key(): string
    {
        return 'doc_'.$this->document->code;
    }

    public function name(): string
    {
        return trim($this->document->code.' - '.$this->document->title);
    }

    protected function documentCode(): string
    {
        return $this->document->code;
    }

    protected function sheetTitle(): string
    {
        return $this->document->title;
    }

    protected function sourceKey(): string
    {
        return $this->programKey;
    }

    protected function resolveDocument(): ?DocumentReference
    {
        return $this->document;
    }

    protected function sheetRecords(CarbonInterface $from, CarbonInterface $to): iterable
    {
        return app(DocumentSources::class)->records($this->programKey, $from, $to);
    }
}
