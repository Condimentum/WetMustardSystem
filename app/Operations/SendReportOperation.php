<?php

namespace App\Operations;

use App\Domains\Reporting\Jobs\ResolveReportRecipientsJob;
use App\Domains\Reporting\ReportRegistry;
use App\Jobs\SendReportEmailJob;
use App\Models\ReportSendLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Generates a report, resolves its recipients, queues the Office 365 send and
 * always writes a send-log row (scope §14.7). Reused by manual Send Now and the
 * scheduled runner. The actual email delivery happens in the background
 * (SendReportEmailJob) so a manual "Send Now" click doesn't block on SMTP.
 */
class SendReportOperation
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ResolveReportRecipientsJob $resolveRecipients,
    ) {
    }

    public function __invoke(
        string $reportKey,
        CarbonInterface $from,
        CarbonInterface $to,
        string $triggerMode,
        ?User $user = null,
    ): ReportSendLog {
        $log = ReportSendLog::create([
            'report_key' => $reportKey,
            'trigger_mode' => $triggerMode,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'status' => ReportSendLog::STATUS_RUNNING,
            'triggered_by' => $user?->id,
            'started_at' => now(),
        ]);

        try {
            $generator = $this->registry->get($reportKey);

            if ($generator === null) {
                throw new \InvalidArgumentException("Report key '{$reportKey}' is not registered.");
            }

            $report = $generator->generate($from, $to);
            $recipients = ($this->resolveRecipients)($reportKey);

            $log->row_count = $report['row_count'];
            $log->recipients_to = implode(', ', $recipients['to']);
            $log->recipients_cc = implode(', ', $recipients['cc']);

            if (($report['skip_if_empty'] ?? false) === true && (int) $report['row_count'] === 0) {
                $log->status = ReportSendLog::STATUS_SKIPPED;
                $log->error_message = 'No trigger material usage found for this period.';
                $log->completed_at = now();
            } elseif ($recipients['to'] === []) {
                $log->status = ReportSendLog::STATUS_SKIPPED;
                $log->error_message = 'No recipients resolved.';
                $log->completed_at = now();
            } else {
                $attachments = is_array($report['attachments'] ?? null)
                    ? $report['attachments']
                    : [];

                $log->save();

                SendReportEmailJob::dispatch(
                    $log->id,
                    $recipients['to'],
                    $report['subject'],
                    $report['html'],
                    $recipients['cc'],
                    $attachments,
                );

                return $log->refresh();
            }
        } catch (Throwable $e) {
            $log->status = ReportSendLog::STATUS_FAILED;
            $log->error_message = $e->getMessage();
            $log->completed_at = now();
        }

        $log->save();

        return $log;
    }
}
