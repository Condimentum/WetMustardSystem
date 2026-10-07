<?php

namespace App\Domains\Reporting\Reports;

use App\Models\ProductionScaleCalibration;
use Carbon\CarbonInterface;

class Wm013ProductionScalesCalibrationReport extends CalibrationSheetReport
{
    public const KEY = 'wm013_production_scales_daily_calibration';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'WM013 Production Scales Daily Calibration';
    }

    protected function documentCode(): string
    {
        return 'WM013';
    }

    protected function sheetTitle(): string
    {
        return 'WM013 Production Scales Daily Calibration';
    }

    protected function sourceKey(): string
    {
        return 'wm013_production_scales';
    }

    protected function sheetRecords(CarbonInterface $from, CarbonInterface $to): iterable
    {
        return ProductionScaleCalibration::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get();
    }
}
