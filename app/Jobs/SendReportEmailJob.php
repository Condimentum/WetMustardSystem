<?php

namespace App\Jobs;

use App\Models\ReportSendLog;
use App\Operations\SendOffice365MailOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Background delivery of an already-created ReportSendLog's email, so the
 * triggering HTTP request (batch entry CCP checks, Send Now, etc.) never
 * blocks on the Office 365/SMTP round trip. Runs via `php artisan queue:work`;
 * under the 'sync' driver (used in tests) it executes immediately in-process.
 */
class SendReportEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, array{path: string, name: string}>  $attachments
     */
    public function __construct(
        private readonly int $reportSendLogId,
        private readonly array $to,
        private readonly string $subject,
        private readonly string $htmlBody,
        private readonly array $cc = [],
        private readonly array $attachments = [],
    ) {
    }

    public function handle(SendOffice365MailOperation $sendMail): void
    {
        $log = ReportSendLog::query()->find($this->reportSendLogId);

        if ($log === null) {
            return;
        }

        try {
            $sendMail($this->to, $this->subject, $this->htmlBody, null, $this->cc, [], [], $this->attachments);
            $log->status = ReportSendLog::STATUS_SENT;
        } catch (Throwable $e) {
            $log->status = ReportSendLog::STATUS_FAILED;
            $log->error_message = $e->getMessage();
        } finally {
            $log->completed_at = now();
            $log->save();
        }
    }
}
