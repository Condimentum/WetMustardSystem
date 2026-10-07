<?php

namespace App\Domains\Reporting\Reports;

use App\Models\SaltMeterCalibration;
use Carbon\CarbonInterface;

class Wm002SaltMeterCalibrationReport extends CalibrationSheetReport
{
    public const KEY = 'wm002_salt_meter_daily_calibration';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'WM002 Daily Salt Meter Calibration';
    }

    protected function documentCode(): string
    {
        return 'WM002';
    }

    protected function sheetTitle(): string
    {
        return 'WM002 Daily Salt Meter Calibration';
    }

    protected function sourceKey(): string
    {
        return 'wm002_salt_meter';
    }

    protected function sheetRecords(CarbonInterface $from, CarbonInterface $to): iterable
    {
        return SaltMeterCalibration::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get();
    }
}
