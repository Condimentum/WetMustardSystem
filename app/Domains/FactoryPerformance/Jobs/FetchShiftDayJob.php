<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptOperationalHours;

/** Loads today's (or any single day's) shift row for one department, if one exists. */
class FetchShiftDayJob
{
    public function __invoke(int $departmentId, string $date): ?FptOperationalHours
    {
        return FptOperationalHours::query()
            ->where('DepartmentId', $departmentId)
            ->where('OperationDate', $date)
            ->first();
    }
}
