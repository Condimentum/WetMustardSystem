<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptOperationalHours;

/**
 * Upserts one department+date shift row (mirrors Factory Performance Tracker's
 * save_row/submit_row handler). A non-working day with no times entered and no
 * explicit submit/persist request clears the row entirely instead of saving it.
 */
class UpsertShiftDayJob
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
        bool $submit = false,
        bool $persistNonWorking = false,
    ): string {
        $hasData = (bool) ($shiftStart || $productionStart || $shiftFinish || $productionFinish);

        if (! $hasData && ! $isWorkingDay && ! $submit && ! $persistNonWorking) {
            FptOperationalHours::query()
                ->where('DepartmentId', $departmentId)
                ->where('OperationDate', $date)
                ->delete();

            return 'cleared';
        }

        $row = FptOperationalHours::query()->firstOrNew([
            'DepartmentId' => $departmentId,
            'OperationDate' => $date,
        ]);

        $row->fill([
            'IsWorkingDay' => $isWorkingDay,
            'ShiftStartTime' => $shiftStart,
            'ProductionStartTime' => $productionStart,
            'ShiftFinishTime' => $shiftFinish,
            'ProductionFinishTime' => $productionFinish,
            'RecordedBy' => $recordedBy,
            'ShiftEndRecordedBy' => $shiftEndRecordedBy ?: null,
            'PackingCompleted' => $packingCompleted,
            'PackingHours' => $packingHours,
        ]);

        if ($submit) {
            $row->IsSubmitted = true;
        }

        $row->save();

        return $submit ? 'submitted' : 'saved';
    }
}
