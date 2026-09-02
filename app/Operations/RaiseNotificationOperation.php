<?php

namespace App\Operations;

use App\Domains\Notification\Jobs\RaiseNotificationEventJob;
use App\Domains\Notification\Jobs\ResolveNotificationRecipientsJob;
use App\Jobs\SendReportEmailJob;
use App\Models\NotificationEvent;
use App\Models\ReportSendLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Raises a notification event (honouring rule enablement + cooldown) and, when
 * recipients exist, queues an alert email and writes an alert send-log row
 * (scope §11 - every alert-driven send must create a send-log entry). The
 * email itself is sent in the background (SendReportEmailJob) so recording a
 * CCP failure/weight breach never blocks the operator's request on SMTP.
 *
 * Reused by immediate event triggers (CCP failure, weight breach) and by the
 * scheduled detector command.
 */
class RaiseNotificationOperation
{
    public function __construct(
        private readonly RaiseNotificationEventJob $raiseEvent,
        private readonly ResolveNotificationRecipientsJob $resolveRecipients,
    ) {
    }

    public function __invoke(string $ruleKey, ?Model $entity, string $message, ?string $severity = null): ?NotificationEvent
    {
        $event = ($this->raiseEvent)($ruleKey, $entity, $message, $severity);

        if ($event === null) {
            return null;
        }

        $recipients = ($this->resolveRecipients)($ruleKey);

        if ($recipients === []) {
            return $event;
        }

        $log = ReportSendLog::create([
            'report_key' => $ruleKey,
            'trigger_mode' => 'alert',
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'recipients_to' => implode(', ', $recipients),
            'status' => ReportSendLog::STATUS_RUNNING,
            'row_count' => 1,
            'started_at' => now(),
        ]);

        SendReportEmailJob::dispatch(
            $log->id,
            $recipients,
            "[DBMTS {$event->severity}] {$event->rule_key}",
            $this->html($event),
        );

        return $event;
    }

    private function html(NotificationEvent $event): string
    {
        return sprintf(
            '<div style="font-family:Arial,sans-serif;"><h3 style="color:#b91c1c;">DBMTS Alert: %s</h3><p><strong>Severity:</strong> %s</p><p>%s</p><p style="color:#6b7280;font-size:12px;">Triggered %s</p></div>',
            e($event->rule_key),
            e($event->severity),
            e($event->message),
            $event->triggered_at?->toDateTimeString(),
        );
    }
}
