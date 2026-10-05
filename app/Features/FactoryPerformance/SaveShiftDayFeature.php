<?php

namespace App\Features\FactoryPerformance;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\FactoryPerformance\Jobs\FetchShiftDayJob;
use App\Domains\FactoryPerformance\Jobs\SyncPackingMirrorJob;
use App\Domains\FactoryPerformance\Jobs\UpsertShiftDayJob;
use App\Models\User;

/** Saves or submits today's Wet Mustard - Manufacturing shift row (shared with Factory Performance Tracker). */
class SaveShiftDayFeature
{
    public function __invoke(
        int $departmentId,
        string $date,
        bool $isWorkingDay,
        ?string $shiftStart,
        ?string $productionStart,
        ?string $shiftFinish,
        ?string $productionFinish,
        string $recordedBy,
        ?string $shiftEndRecordedBy,
        bool $packingCompleted,
        ?float $packingHours,
        bool $submit,
        ?User $user = null,
    ): string {
        $status = app(UpsertShiftDayJob::class)(
            $departmentId,
            $date,
            $isWorkingDay,
            $shiftStart,
            $productionStart,
            $shiftFinish,
            $productionFinish,
            $recordedBy,
            $shiftEndRecordedBy,
            $packingCompleted,
            $packingHours,
            $submit,
        );

        app(SyncPackingMirrorJob::class)($date, $isWorkingDay, $shiftFinish, $packingCompleted, $packingHours, $recordedBy);

        $row = app(FetchShiftDayJob::class)($departmentId, $date);

        if ($row) {
            app(RecordAuditEntryJob::class)(
                $row,
                'update',
                $user,
                reason: "Factory Performance Tracker shift record {$status} via DBMTS.",
            );
        }

        return $status;
    }
}
