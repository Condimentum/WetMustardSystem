<?php

namespace App\Domains\FactoryPerformance\Jobs;

use Illuminate\Support\Facades\DB;

/** Sets (or clears) the lock flag on a day's downtime submission record. */
class SetDowntimeSubmittedJob
{
    public function __invoke(int $departmentId, string $date, bool $isSubmitted, ?string $submittedBy = null): void
    {
        $table = DB::connection(config('fpt.connection'))->table('DowntimeSubmissions');

        if ($isSubmitted) {
            $updated = $table->clone()
                ->where('DepartmentId', $departmentId)
                ->where('EventDate', $date)
                ->update([
                    'IsSubmitted' => true,
                    'SubmittedBy' => $submittedBy,
                    'ModifiedDate' => now(),
                ]);

            if ($updated === 0) {
                $table->insert([
                    'DepartmentId' => $departmentId,
                    'EventDate' => $date,
                    'IsSubmitted' => true,
                    'SubmittedBy' => $submittedBy,
                    'SubmittedDate' => now(),
                ]);
            }

            return;
        }

        $table->clone()
            ->where('DepartmentId', $departmentId)
            ->where('EventDate', $date)
            ->update(['IsSubmitted' => false, 'ModifiedDate' => now()]);
    }
}
