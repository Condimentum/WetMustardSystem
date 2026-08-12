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

    protected function sheetHeaders(): array
    {
        return ['Date', 'Reading (mg/l)', 'Pass/Fail', 'Operator Name', 'Deviation / Action'];
    }

    protected function sheetInstructions(): array
    {
        return [
            'Target reading is 100 +/- 2 mg/l.',
            'If out of tolerance, check pipette, tip, and re-inject chloride solution before adjusting meter.',
            'Inform QA if target cannot be achieved and record action taken.',
        ];
    }

    protected function sheetRows(CarbonInterface $from, CarbonInterface $to): array
    {
        return SaltMeterCalibration::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get()
            ->map(fn (SaltMeterCalibration $row): array => [
                $row->checked_date?->toDateString() ?? '—',
                number_format((float) $row->reading, 3, '.', ''),
                $row->passed ? 'Pass' : 'Fail',
                (string) $row->operator_name,
                (string) ($row->deviation_reason ?? '—'),
            ])
            ->all();
    }
}
