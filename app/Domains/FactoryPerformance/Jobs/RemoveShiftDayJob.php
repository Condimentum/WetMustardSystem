<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptDowntimeEvent;
use App\Models\FptOperationalHours;

/** Deletes a day's shift row and its downtime events entirely (the "Remove" button). */
class RemoveShiftDayJob
{
    public function __invoke(int $departmentId, string $date): void
    {
        FptOperationalHours::query()
            ->where('DepartmentId', $departmentId)
            ->where('OperationDate', $date)
            ->delete();

        FptDowntimeEvent::query()
            ->where('DepartmentId', $departmentId)
            ->where('EventDate', $date)
            ->whereIn('EventType', ['Downtime', 'Issue'])
            ->delete();
    }
}
