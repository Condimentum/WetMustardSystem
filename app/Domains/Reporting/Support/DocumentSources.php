<?php

namespace App\Domains\Reporting\Support;

use App\Models\DocumentReference;
use App\Models\LabScaleCalibration;
use App\Models\ManufacturingOrder;
use App\Models\ProductionScaleCalibration;
use App\Models\SaltMeterCalibration;
use App\Models\ViscosityMeterAutozeroCheck;
use App\Models\WinManIssueLog;
use Closure;
use Illuminate\Support\Facades\Schema;

/**
 * Registry of the data sources a controlled document can be linked to in
 * Settings > Documents, and the fields (columns) each source can print.
 *
 * A document is either "program driven" (filled from one of the app's
 * programs, e.g. the WM002 salt meter calibration screen) or "material
 * triggered" (generated when a configured raw material is issued to a batch).
 * The Columns editor, its preview and the generated PDFs all read the same
 * field definitions from here.
 */
class DocumentSources
{
    public const TYPE_PROGRAM = 'program';

    public const TYPE_MATERIAL_TRIGGER = 'material_trigger';

    /** Explicitly not linked (stored so the code-prefix fallback no longer applies). */
    public const TYPE_NONE = 'none';

    /** Source key used for material-triggered documents (programs use their own keys). */
    public const MATERIAL_TRIGGER = 'material_trigger';

    private static ?bool $hasSourceColumns = null;

    /**
     * Programs a document can be linked to, keyed by program key.
     *
     * @return array<string, array{label: string, code: string, configurable_pdf: bool, instructions: array<int, string>}>
     */
    public function programs(): array
    {
        return [
            'wm001_lab_scales' => [
                'label' => 'Lab Scales Daily Calibration',
                'code' => 'WM001',
                'configurable_pdf' => true,
                'instructions' => [
                    'Push and hold the Tare button until the display shows 0.00g.',
                    'Place the 100g weight on the scales bed.',
                    'Target: 100 +/- 0.02g. Out-of-spec results must be recorded with action.',
                ],
            ],
            'wm002_salt_meter' => [
                'label' => 'Daily Salt Meter Calibration',
                'code' => 'WM002',
                'configurable_pdf' => true,
                'instructions' => [
                    'Target reading is 100 +/- 2 mg/l.',
                    'If out of tolerance, check pipette, tip, and re-inject chloride solution before adjusting meter.',
                    'Inform QA if target cannot be achieved and record action taken.',
                ],
            ],
            'wm003_traceability' => [
                'label' => 'Raw Material Traceability',
                'code' => 'WM003',
                'configurable_pdf' => false,
                'instructions' => [],
            ],
            'wm004_pallecon' => [
                'label' => 'Pallecon Bulk Fill Traceability',
                'code' => 'WM004',
                'configurable_pdf' => false,
                'instructions' => [],
            ],
            'wm005_lab_testing' => [
                'label' => 'Wet Mustard Lab Testing',
                'code' => 'WM005',
                'configurable_pdf' => true,
                'instructions' => [],
            ],
            'wm006_viscosity_autozero' => [
                'label' => 'Viscosity Meter Autozero',
                'code' => 'WM006',
                'configurable_pdf' => true,
                'instructions' => [
                    'Follow instructions on COP WMUS004 to autozero viscosity meter.',
                    'If check is not complete, inform QA and record reason/action.',
                ],
            ],
            'wm010_rinse_water' => [
                'label' => 'Rinse Water Test',
                'code' => 'WM010',
                'configurable_pdf' => false,
                'instructions' => [],
            ],
            'wm013_production_scales' => [
                'label' => 'Production Scales Calibration',
                'code' => 'WM013',
                'configurable_pdf' => true,
                'instructions' => [
                    '100g weight should read 100 +/- 0.02g on powder 3kg scale.',
                    '10kg should read 10 +/- 0.1kg on powder 30kg and bucket filler scales.',
                    'Pallecon scale tolerance is 10 +/- 1kg.',
                    'Record out-of-spec actions and inform QA where needed.',
                ],
            ],
        ];
    }

    /**
     * Field definitions for a source key (a program key or MATERIAL_TRIGGER).
     *
     * @return array<int, array{key: string, label: string, width: float, visible: bool, sample: string, value: (Closure(mixed): string)|null}>
     */
    public function fields(?string $sourceKey): array
    {
        return match ($sourceKey) {
            'wm001_lab_scales' => $this->readingCalibrationFields(LabScaleCalibration::class),
            'wm002_salt_meter' => $this->readingCalibrationFields(SaltMeterCalibration::class),
            'wm003_traceability' => [
                $this->field('date_used', 'Date Used', 16, '2026-08-12'),
                $this->field('supplier_production_date', 'Supplier Production Date', 18, '2026-08-12'),
                $this->field('best_before_date', 'Best Before Date', 16, '2026-08-12'),
                $this->field('batch_no', 'Batch No.', 16, 'hqwidj'),
                $this->field('time_on', 'Time On', 12, '06:30'),
                $this->field('operator_name', 'Operator Name', 22, 'Adrian Lacki'),
            ],
            'wm004_pallecon' => [
                $this->field('mo_number', 'MO Number', 8, '1224'),
                $this->field('ticket_number', 'Ticket Number', 9, 'TK-10092'),
                $this->field('serial_number', 'Serial Number', 10, 'S0400332'),
                $this->field('top_seal_number', 'Top Seal Number', 8, 'TS8821'),
                $this->field('bottom_seal_number', 'Bottom Seal Number', 8, 'BS9912'),
                $this->field('liner_number', 'Liner Number', 7, 'LN-22'),
                $this->field('liner_batch_code', 'Liner Batch Code', 8, 'LBC-722A'),
                $this->field('fill_weight', 'Fill Weight', 7, '1000.000'),
                $this->field('start_time', 'Start Time', 8, '07:00'),
                $this->field('finish_time', 'Finish Time', 8, '07:55'),
                $this->field('checked_by', 'Checked By', 9, 'Operator QA'),
                $this->field('checked_at', 'Checked At', 8, '2026-08-12 08:00'),
            ],
            'wm005_lab_testing' => [
                $this->field('tested_date', 'Date', 6, '2026-08-12'),
                $this->field('tested_time', 'Time', 4, '06:30'),
                $this->field('mo_number', 'MO Number', 8, '1224'),
                $this->field('batch_number', 'Batch Number', 8, 'hqwidj'),
                $this->field('ph', 'pH', 10, '23.000'),
                $this->field('acidity_acetic', 'Acidity (as acetic)', 10, '32.000'),
                $this->field('acidity_citric', 'Acidity (as citric)', 5, '21.000'),
                $this->field('salt', 'Salt', 12, '23.000'),
                $this->field('viscosity_brookfield', 'Viscosity (Brookfield)', 11, '41.000'),
                $this->field('viscosity_bostwick', 'Viscosity (Bostwick)', 5, '12.000'),
                $this->field('aw', 'aW', 5, '12.000'),
                $this->field('solids', 'Solids', 10, '123.000'),
                $this->field('appearance', 'Appearance', 10, 'Pass'),
                $this->field('tested_by', 'Test by (Print name)', 11, 'Adrian Lacki'),
            ],
            'wm006_viscosity_autozero' => [
                $this->field('checked_date', 'Date', 18, '2026-08-12', fn (ViscosityMeterAutozeroCheck $row): string => $row->checked_date?->toDateString() ?? '—'),
                $this->field('complete', 'Complete Y/N', 14, 'Yes', fn (ViscosityMeterAutozeroCheck $row): string => $row->complete ? 'Yes' : 'No'),
                $this->field('operator_name', 'Operator Name', 26, 'Adrian Lacki', fn (ViscosityMeterAutozeroCheck $row): string => (string) $row->operator_name),
                $this->field('deviation_reason', 'Deviation / Action', 42, '—', fn (ViscosityMeterAutozeroCheck $row): string => (string) ($row->deviation_reason ?? '—')),
            ],
            'wm010_rinse_water' => [
                $this->field('section', 'Section', 18, 'Mixing'),
                $this->field('equipment', 'Equipment', 18, 'Vessel 1'),
                $this->field('reading', 'Reading', 12, '12.5 ppm'),
                $this->field('target', 'Target', 10, '< 20'),
                $this->field('result', 'Result', 10, 'OK'),
                $this->field('pass_or_fail', 'Pass / Fail', 10, 'Pass'),
                $this->field('action_taken_if_failed', 'Action if Failed', 12, '—'),
                $this->field('operator_name', 'Operator Name', 10, 'Adrian Lacki'),
            ],
            'wm013_production_scales' => [
                $this->field('checked_date', 'Date', 11, '2026-08-12', fn (ProductionScaleCalibration $row): string => $row->checked_date?->toDateString() ?? '—'),
                $this->field('powder_3kg_reading', 'Powder 3kg', 10, '100.010', fn (ProductionScaleCalibration $row): string => $this->decimal($row->powder_3kg_reading)),
                $this->field('powder_30kg_reading', 'Powder 30kg', 10, '10.020', fn (ProductionScaleCalibration $row): string => $this->decimal($row->powder_30kg_reading)),
                $this->field('pallecon_scale_reading', 'Pallecon', 10, '10.400', fn (ProductionScaleCalibration $row): string => $this->decimal($row->pallecon_scale_reading)),
                $this->field('bucket_filler_scale_reading', 'Bucket Filler', 10, '10.050', fn (ProductionScaleCalibration $row): string => $this->decimal($row->bucket_filler_scale_reading)),
                $this->field('passed', 'Pass/Fail', 9, 'Pass', fn (ProductionScaleCalibration $row): string => $row->passed ? 'Pass' : 'Fail'),
                $this->field('operator_name', 'Operator Name', 15, 'Adrian Lacki', fn (ProductionScaleCalibration $row): string => (string) $row->operator_name),
                $this->field('deviation_reason', 'Deviation / Action', 25, '—', fn (ProductionScaleCalibration $row): string => (string) ($row->deviation_reason ?? '—')),
            ],
            self::MATERIAL_TRIGGER => [
                $this->field('issue_date', 'Date/Time', 13, '2026-08-12 06:30', fn (WinManIssueLog $log): string => $log->issue_date?->format('Y-m-d H:i') ?? '—'),
                $this->field('batch_number', 'Batch', 12, 'WM-24113', fn (WinManIssueLog $log): string => (string) ($log->batchRecord?->batch_number ?? '—')),
                $this->field('mo_reference', 'MO Ref', 10, 'MO001224', fn (WinManIssueLog $log): string => $this->moReference($log)),
                $this->field('material_code', 'Material Code', 11, '90010012', fn (WinManIssueLog $log): string => (string) $log->material_code),
                $this->field('material_description', 'Material', 18, '5kg Bucket White', fn (WinManIssueLog $log): string => (string) ($log->batchIngredientLot?->material_description ?? '—')),
                $this->field('lot_number', 'Lot Number', 12, 'LOT-22841', fn (WinManIssueLog $log): string => (string) ($log->lot_number ?? '—')),
                $this->field('quantity_issued', 'Qty Issued (kg)', 11, '25.500', fn (WinManIssueLog $log): string => $this->decimal($log->quantity_issued)),
                $this->field('issue_user', 'Issued By', 13, 'Adrian Lacki', fn (WinManIssueLog $log): string => (string) ($log->issue_user ?? '—')),
            ],
            default => [],
        };
    }

    /**
     * Column settings (key/label/width/visible) used when a document has none saved.
     *
     * @return array<int, array{key: string, label: string, width: float, visible: bool}>
     */
    public function defaultColumns(?string $sourceKey): array
    {
        return array_map(
            fn (array $field): array => [
                'key' => $field['key'],
                'label' => $field['label'],
                'width' => $field['width'],
                'visible' => $field['visible'],
            ],
            $this->fields($sourceKey),
        );
    }

    /** @return array<int, string> */
    public function defaultInstructions(?string $sourceKey): array
    {
        return $this->programs()[$sourceKey]['instructions'] ?? [];
    }

    /**
     * Sample cell values for every known field key, used by the layout preview.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        $samples = [];
        foreach ([...array_keys($this->programs()), self::MATERIAL_TRIGGER] as $sourceKey) {
            foreach ($this->fields($sourceKey) as $field) {
                $samples[$field['key']] ??= $field['sample'];
            }
        }

        return $samples;
    }

    public function isProgram(?string $programKey): bool
    {
        return $programKey !== null && array_key_exists($programKey, $this->programs());
    }

    public function programKeyForCode(?string $documentCode): ?string
    {
        $code = strtoupper(trim((string) $documentCode));
        if ($code === '') {
            return null;
        }

        foreach ($this->programs() as $key => $program) {
            if (str_starts_with($code, $program['code'])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The source type and program key a document is linked to. Documents saved
     * before linking existed fall back to: material trigger when trigger codes
     * are configured, otherwise the program whose code prefix matches.
     *
     * @return array{type: string|null, program_key: string|null}
     */
    public function linkFor(?DocumentReference $document, ?string $fallbackCode = null): array
    {
        $storedType = $document?->getAttribute('source_type');
        $storedProgram = $document?->getAttribute('program_key');

        if ($storedType === self::TYPE_MATERIAL_TRIGGER) {
            return ['type' => self::TYPE_MATERIAL_TRIGGER, 'program_key' => null];
        }

        if ($storedType === self::TYPE_PROGRAM && $this->isProgram($storedProgram)) {
            return ['type' => self::TYPE_PROGRAM, 'program_key' => $storedProgram];
        }

        if ($storedType === null && ! empty($document?->trigger_material_codes)) {
            return ['type' => self::TYPE_MATERIAL_TRIGGER, 'program_key' => null];
        }

        if ($storedType === null) {
            $programKey = $this->programKeyForCode($document?->code ?? $fallbackCode);
            if ($programKey !== null) {
                return ['type' => self::TYPE_PROGRAM, 'program_key' => $programKey];
            }
        }

        return ['type' => null, 'program_key' => null];
    }

    public function sourceKey(?string $type, ?string $programKey): ?string
    {
        return match ($type) {
            self::TYPE_MATERIAL_TRIGGER => self::MATERIAL_TRIGGER,
            self::TYPE_PROGRAM => $this->isProgram($programKey) ? $programKey : null,
            default => null,
        };
    }

    public function sourceKeyFor(?DocumentReference $document, ?string $fallbackCode = null): ?string
    {
        $link = $this->linkFor($document, $fallbackCode);

        return $this->sourceKey($link['type'], $link['program_key']);
    }

    /**
     * The document linked to a program: an explicit link wins, otherwise the
     * document whose code matches the program's code.
     */
    public function documentForProgram(string $programKey): ?DocumentReference
    {
        if ($this->hasSourceColumns()) {
            $linked = DocumentReference::query()
                ->where('source_type', self::TYPE_PROGRAM)
                ->where('program_key', $programKey)
                ->orderBy('code')
                ->first();

            if ($linked !== null) {
                return $linked;
            }
        }

        $code = $this->programs()[$programKey]['code'] ?? null;

        return $code !== null ? DocumentReference::query()->where('code', $code)->first() : null;
    }

    public function hasSourceColumns(): bool
    {
        if (self::$hasSourceColumns === null) {
            try {
                self::$hasSourceColumns = Schema::hasColumn('document_references', 'source_type');
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$hasSourceColumns;
    }

    /**
     * @param  class-string  $model
     * @return array<int, array<string, mixed>>
     */
    private function readingCalibrationFields(string $model): array
    {
        return [
            $this->field('checked_date', 'Date', 18, '2026-08-12', fn (LabScaleCalibration|SaltMeterCalibration $row): string => $row->checked_date?->toDateString() ?? '—'),
            $this->field('reading', $model === SaltMeterCalibration::class ? 'Reading (mg/l)' : 'Reading', 16, '100.010', fn (LabScaleCalibration|SaltMeterCalibration $row): string => $this->decimal($row->reading)),
            $this->field('passed', 'Pass/Fail', 12, 'Pass', fn (LabScaleCalibration|SaltMeterCalibration $row): string => $row->passed ? 'Pass' : 'Fail'),
            $this->field('operator_name', 'Operator Name', 22, 'Adrian Lacki', fn (LabScaleCalibration|SaltMeterCalibration $row): string => (string) $row->operator_name),
            $this->field('deviation_reason', 'Deviation / Action', 32, '—', fn (LabScaleCalibration|SaltMeterCalibration $row): string => (string) ($row->deviation_reason ?? '—')),
        ];
    }

    /** @return array{key: string, label: string, width: float, visible: bool, sample: string, value: Closure|null} */
    private function field(string $key, string $label, float $width, string $sample, ?Closure $value = null, bool $visible = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'width' => $width,
            'visible' => $visible,
            'sample' => $sample,
            'value' => $value,
        ];
    }

    private function decimal(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 3, '.', '');
    }

    private function moReference(WinManIssueLog $log): string
    {
        if ($log->winman_manufacturing_order === null) {
            return '—';
        }

        $moRef = ManufacturingOrder::query()
            ->where('winman_manufacturing_order', $log->winman_manufacturing_order)
            ->value('winman_manufacturing_order_id');

        return (string) ($moRef ?? $log->winman_manufacturing_order);
    }
}
