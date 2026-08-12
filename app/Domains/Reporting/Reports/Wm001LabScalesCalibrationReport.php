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

    protected function sheetHeaders(): array
    {
        return ['Date', 'Reading', 'Pass/Fail', 'Operator Name', 'Deviation / Action'];
    }

    protected function sheetInstructions(): array
    {
        return [
            'Push and hold the Tare button until the display shows 0.00g.',
            'Place the 100g weight on the scales bed.',
            'Target: 100 +/- 0.02g. Out-of-spec results must be recorded with action.',
        ];
    }

    protected function sheetRows(CarbonInterface $from, CarbonInterface $to): array
    {
        return LabScaleCalibration::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get()
            ->map(fn (LabScaleCalibration $row): array => [
                $row->checked_date?->toDateString() ?? '—',
                number_format((float) $row->reading, 3, '.', ''),
                $row->passed ? 'Pass' : 'Fail',
                (string) $row->operator_name,
                (string) ($row->deviation_reason ?? '—'),
            ])
            ->all();
    }
}
