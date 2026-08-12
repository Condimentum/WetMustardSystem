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

    protected function sheetHeaders(): array
    {
        return ['Date', 'Complete Y/N', 'Operator Name', 'Deviation / Action'];
    }

    protected function sheetInstructions(): array
    {
        return [
            'Follow instructions on COP WMUS004 to autozero viscosity meter.',
            'If check is not complete, inform QA and record reason/action.',
        ];
    }

    protected function sheetRows(CarbonInterface $from, CarbonInterface $to): array
    {
        return ViscosityMeterAutozeroCheck::query()
            ->whereDate('checked_date', '>=', $from->toDateString())
            ->whereDate('checked_date', '<=', $to->toDateString())
            ->orderBy('checked_date')
            ->orderBy('id')
            ->get()
            ->map(fn (ViscosityMeterAutozeroCheck $row): array => [
                $row->checked_date?->toDateString() ?? '—',
                $row->complete ? 'Yes' : 'No',
                (string) $row->operator_name,
                (string) ($row->deviation_reason ?? '—'),
            ])
            ->all();
    }
}
