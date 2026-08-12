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

    protected function sheetHeaders(): array
    {
        return ['Date', 'Powder 3kg', 'Powder 30kg', 'Pallecon', 'Bucket Filler', 'Pass/Fail', 'Operator Name', 'Deviation / Action'];
    }

    protected function sheetInstructions(): array
    {
        return [
            '100g weight should read 100 +/- 0.02g on powder 3kg scale.',
            '10kg should read 10 +/- 0.1kg on powder 30kg and bucket filler scales.',
            'Pallecon scale tolerance is 10 +/- 1kg.',
            'Record out-of-spec actions and inform QA where needed.',
        ];
    }

    protected function sheetRows(CarbonInterface $from, CarbonInterface $to): array
    {
        return ProductionScaleCalibration::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductionScaleCalibration $row): array => [
                $row->checked_date?->toDateString() ?? '—',
                number_format((float) $row->powder_3kg_reading, 3, '.', ''),
                number_format((float) $row->powder_30kg_reading, 3, '.', ''),
                number_format((float) $row->pallecon_scale_reading, 3, '.', ''),
                number_format((float) $row->bucket_filler_scale_reading, 3, '.', ''),
                $row->passed ? 'Pass' : 'Fail',
                (string) $row->operator_name,
                (string) ($row->deviation_reason ?? '—'),
            ])
            ->all();
    }
}
