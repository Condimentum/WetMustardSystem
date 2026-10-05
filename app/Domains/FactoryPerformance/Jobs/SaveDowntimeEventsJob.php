<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptDowntimeEvent;

/**
 * Replaces a whole day's downtime events (mirrors Factory Performance Tracker's
 * saveDowntimeEventsForDay: delete-then-reinsert, not an incremental diff).
 * Each event needs at least a start time; rows with nothing filled in are skipped.
 */
class SaveDowntimeEventsJob
{
    public function __invoke(int $departmentId, string $date, string $recordedBy, array $events): void
    {
        FptDowntimeEvent::query()
            ->where('DepartmentId', $departmentId)
            ->where('EventDate', $date)
            ->whereIn('EventType', ['Downtime', 'Issue'])
            ->delete();

        foreach ($events as $event) {
            $reasonId = (int) ($event['reason_id'] ?? 0);
            $start = trim((string) ($event['start_time'] ?? ''));
            $end = trim((string) ($event['end_time'] ?? ''));
            $comment = trim((string) ($event['comment'] ?? ''));

            if ($reasonId <= 0 && $start === '' && $end === '' && $comment === '') {
                continue;
            }

            if (! preg_match('/^\d{2}:\d{2}$/', $start)) {
                continue;
            }

            FptDowntimeEvent::query()->create([
                'DepartmentId' => $departmentId,
                'EventDate' => $date,
                'StartTime' => $start,
                'EndTime' => preg_match('/^\d{2}:\d{2}$/', $end) ? $end : null,
                'EventType' => 'Downtime',
                'ReasonId' => $reasonId > 0 ? $reasonId : null,
                'Description' => $comment !== '' ? $comment : null,
                'RecordedBy' => $recordedBy ?: null,
            ]);
        }
    }
}
