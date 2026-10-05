<?php

namespace App\Features\FactoryPerformance;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\FactoryPerformance\Jobs\FetchShiftDayJob;
use App\Domains\FactoryPerformance\Jobs\UnlockShiftDayJob;
use App\Models\User;

/** Reopens a previously-submitted shift row for editing. */
class UnlockShiftDayFeature
{
    public function __invoke(int $departmentId, string $date, ?User $user = null): void
    {
        app(UnlockShiftDayJob::class)($departmentId, $date);

        $row = app(FetchShiftDayJob::class)($departmentId, $date);

        if ($row) {
            app(RecordAuditEntryJob::class)($row, 'update', $user, 'IsSubmitted', 1, 0, 'Shift record unlocked for editing via DBMTS.');
        }
    }
}
