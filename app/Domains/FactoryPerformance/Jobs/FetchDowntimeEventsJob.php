<?php

namespace App\Domains\FactoryPerformance\Jobs;

use Illuminate\Support\Collection;
use App\Models\FptDowntimeEvent;

/** Lists a day's downtime events (with their reason name joined in) for display. */
class FetchDowntimeEventsJob
{
    public function __invoke(int $departmentId, string $date): Collection
    {
        return FptDowntimeEvent::query()
            ->leftJoin('DowntimeReasons', 'DowntimeReasons.ReasonId', '=', 'DowntimeEvents.ReasonId')
            ->where('DowntimeEvents.DepartmentId', $departmentId)
            ->where('DowntimeEvents.EventDate', $date)
            ->whereIn('DowntimeEvents.EventType', ['Downtime', 'Issue'])
            ->orderBy('DowntimeEvents.StartTime')
            ->select([
                'DowntimeEvents.EventId',
                'DowntimeEvents.ReasonId',
                'DowntimeReasons.ReasonName',
                'DowntimeEvents.StartTime',
                'DowntimeEvents.EndTime',
                'DowntimeEvents.Description as Comment',
            ])
            ->get();
    }
}
