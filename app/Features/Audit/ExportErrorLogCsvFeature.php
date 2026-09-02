<?php

namespace App\Features\Audit;

use App\Models\ErrorLog;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a filtered error log as a CSV download.
 */
class ExportErrorLogCsvFeature
{
    public function __construct(
        private readonly GenerateErrorLogReportFeature $generate,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __invoke(array $filters): StreamedResponse
    {
        $rows = ($this->generate)($filters);
        $filename = 'error-log-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Timestamp', 'Level', 'Context', 'Message', 'Exception', 'URL', 'Method', 'User']);

            foreach ($rows as $row) {
                /** @var ErrorLog $row */
                fputcsv($handle, [
                    $row->created_at?->toDateTimeString(),
                    $row->level,
                    $row->context,
                    $row->message,
                    $row->exception_class,
                    $row->url,
                    $row->http_method,
                    $row->user?->name,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
