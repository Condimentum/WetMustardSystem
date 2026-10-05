<?php

namespace App\Features\FactoryPerformance;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\FactoryPerformance\Jobs\FetchShiftDayJob;
use App\Domains\FactoryPerformance\Jobs\RemoveShiftDayJob;
use App\Domains\FactoryPerformance\Jobs\SetDowntimeSubmittedJob;
use App\Domains\FactoryPerformance\Jobs\SyncPackingMirrorJob;
use App\Models\User;

/** Removes a day's shift row, its downtime events, and clears the packing mirror. */
class RemoveShiftDayFeature
{
    public function __invoke(int $departmentId, string $date, ?User $user = null): void
    {
        $row = app(FetchShiftDayJob::class)($departmentId, $date);

        app(RemoveShiftDayJob::class)($departmentId, $date);
        app(SyncPackingMirrorJob::class)($date, false, null, false, null, null);
        app(SetDowntimeSubmittedJob::class)($departmentId, $date, false);

        if ($row) {
            app(RecordAuditEntryJob::class)($row, 'delete', $user, reason: 'Factory Performance Tracker shift record removed via DBMTS.');
        }
    }
}
