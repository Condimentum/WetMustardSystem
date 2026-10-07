<?php

namespace App\Domains\Reporting\Reports;

use App\Models\LabScaleCalibration;
use Carbon\CarbonInterface;

class Wm001LabScalesCalibrationReport extends CalibrationSheetReport
{
    public const KEY = 'wm001_lab_scales_daily_calibration';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'WM001 Lab Scales Daily Calibration';
    }

    protected function documentCode(): string
    {
        return 'WM001';
    }

    protected function sheetTitle(): string
    {
        return 'WM001 Lab Scales Daily Calibration';
    }

    protected function sourceKey(): string
    {
        return 'wm001_lab_scales';
    }

    protected function sheetRecords(CarbonInterface $from, CarbonInterface $to): iterable
    {
        return LabScaleCalibration::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get();
    }
}
