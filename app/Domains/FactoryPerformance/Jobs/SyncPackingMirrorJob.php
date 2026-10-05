<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptDepartment;
use App\Models\FptOperationalHours;

/**
 * Mirrors the Packing Hours allowance from a Wet Mustard - Manufacturing shift
 * into its own row on the sibling Wet Mustard - Packing department, so that
 * department's own OEE reporting in Factory Performance Tracker picks it up.
 * Packing window = (Shift Finish - Packing Hours) to Shift Finish.
 */
class SyncPackingMirrorJob
{
    public function __invoke(
        string $date,
        bool $isWorkingDay,
        ?string $shiftFinish,
        bool $packingCompleted,
        ?float $packingHours,
        ?string $recordedBy,
    ): void {
        $packingDepartmentId = FptDepartment::query()
            ->where('DepartmentName', config('fpt.packing_department_name'))
            ->value('DepartmentId');

        if (! $packingDepartmentId) {
            return;
        }

        if (! $isWorkingDay || ! $packingCompleted || ! $packingHours || $packingHours <= 0 || ! $shiftFinish) {
            FptOperationalHours::query()
                ->where('DepartmentId', $packingDepartmentId)
                ->where('OperationDate', $date)
                ->delete();

            return;
        }

        [$finishHours, $finishMinutes] = array_pad(explode(':', $shiftFinish), 2, 0);
        $finishTotalMinutes = ((int) $finishHours * 60) + (int) $finishMinutes;
        $startTotalMinutes = max(0, $finishTotalMinutes - (int) round($packingHours * 60));
        $packingStart = sprintf('%02d:%02d', intdiv($startTotalMinutes, 60), $startTotalMinutes % 60);

        FptOperationalHours::query()->updateOrCreate(
            ['DepartmentId' => $packingDepartmentId, 'OperationDate' => $date],
            [
                'IsWorkingDay' => true,
                'ShiftStartTime' => $packingStart,
                'ShiftFinishTime' => $shiftFinish,
                'RecordedBy' => $recordedBy,
            ],
        );
    }
}
