<?php

namespace App\Domains\FactoryPerformance\Jobs;

use Illuminate\Support\Facades\DB;

/** Whether a day's downtime list has been submitted (locked), independent of the shift row's own submit flag. */
class FetchDowntimeSubmissionJob
{
    public function __invoke(int $departmentId, string $date): bool
    {
        return (bool) DB::connection(config('fpt.connection'))
            ->table('DowntimeSubmissions')
            ->where('DepartmentId', $departmentId)
            ->where('EventDate', $date)
            ->value('IsSubmitted');
    }
}
