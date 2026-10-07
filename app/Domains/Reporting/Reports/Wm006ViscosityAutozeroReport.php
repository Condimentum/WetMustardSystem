<?php

namespace App\Domains\Reporting\Reports;

use App\Models\ViscosityMeterAutozeroCheck;
use Carbon\CarbonInterface;

class Wm006ViscosityAutozeroReport extends CalibrationSheetReport
{
    public const KEY = 'wm006_viscosity_meter_autozero';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'WM006 Viscosity Meter Autozero Check';
    }

    protected function documentCode(): string
    {
        return 'WM006';
    }

    protected function sheetTitle(): string
    {
        return 'WM006 Viscosity Meter Autozero Check Complete';
    }

    protected function sourceKey(): string
    {
        return 'wm006_viscosity_autozero';
    }

    protected function sheetRecords(CarbonInterface $from, CarbonInterface $to): iterable
    {
        return ViscosityMeterAutozeroCheck::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get();
    }
}
