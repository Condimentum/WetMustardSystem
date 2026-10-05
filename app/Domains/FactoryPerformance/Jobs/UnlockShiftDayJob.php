<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptOperationalHours;

/** Unlocks a previously-submitted shift row for editing again. */
class UnlockShiftDayJob
{
    public function __invoke(int $departmentId, string $date): void
    {
        FptOperationalHours::query()
            ->where('DepartmentId', $departmentId)
            ->where('OperationDate', $date)
            ->update(['IsSubmitted' => false]);
    }
}
