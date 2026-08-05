<?php

namespace App\Operations;

use App\Domains\Reporting\Jobs\ResolveReportRecipientsJob;
use App\Domains\Reporting\ReportRegistry;
use App\Models\ReportSendLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Generates a report, resolves its recipients, sends it via Office 365 and
 * always writes a send-log row (scope §14.7). Reused by manual Send Now and the
 * scheduled runner.
 */
class SendReportOperation
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ResolveReportRecipientsJob $resolveRecipients,
        private readonly SendOffice365MailOperation $sendMail,
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

            if ($recipients['to'] === []) {
                $log->status = ReportSendLog::STATUS_SKIPPED;
                $log->error_message = 'No recipients resolved.';
            } else {
                $attachments = is_array($report['attachments'] ?? null)
                    ? $report['attachments']
                    : [];

                ($this->sendMail)(
                    $recipients['to'],
                    $report['subject'],
                    $report['html'],
                    null,
                    $recipients['cc'],
                    [],
                    [],
                    $attachments,
                );
                $log->status = ReportSendLog::STATUS_SENT;
            }
        } catch (Throwable $e) {
            $log->status = ReportSendLog::STATUS_FAILED;
            $log->error_message = $e->getMessage();
        } finally {
            $log->completed_at = now();
            $log->save();
        }

        return $log;
    }
}
