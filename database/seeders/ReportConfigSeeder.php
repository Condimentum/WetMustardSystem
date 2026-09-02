<?php

namespace Database\Seeders;

use App\Domains\Reporting\Reports\DailyIntermediateProductionReport;
use App\Domains\Reporting\Reports\MetalDetectorVerificationSheetReport;
use App\Domains\Reporting\Reports\Wm005WetMustardLabTestingReport;
use App\Domains\Reporting\Reports\Wm010RinseWaterTestReport;
use App\Domains\Reporting\Reports\Wm001LabScalesCalibrationReport;
use App\Domains\Reporting\Reports\Wm002SaltMeterCalibrationReport;
use App\Domains\Reporting\Reports\Wm006ViscosityAutozeroReport;
use App\Domains\Reporting\Reports\Wm013ProductionScalesCalibrationReport;
use App\Models\ReportConfig;
use App\Models\ReportRecipient;
use Illuminate\Database\Seeder;

/**
 * Seeds the scheduled DBMTS report configurations (scope §14.1). Idempotent.
 */
class ReportConfigSeeder extends Seeder
{
    /**
     * Legacy generic reports to remove from Reporting Admin list.
     *
     * @var array<int, string>
     */
    private const REMOVE_KEYS = [
        'dbmts_daily_production_summary',
        'dbmts_open_batches',
        'dbmts_overdue_metal_detect',
        'dbmts_weight_exceptions',
        'dbmts_drum_summary',
        'dbmts_qa_approval_queue',
        'dbmts_traceability_exceptions',
        'dbmts_active_master_data',
        'dbmts_batch_summary',
        'wm003_ibc_traceability', // replaced by WinMan-triggered doc_WM003 (Settings > Documents trigger material codes)
    ];

    /**
     * WM calibration paperwork reports only.
     * [report_key => report_name].
     */
    private const REPORTS = [
        MetalDetectorVerificationSheetReport::KEY => 'Metal Detector Verification Sheet (PDF)',
        Wm005WetMustardLabTestingReport::KEY => 'WM005 Wet Mustard Lab Testing (PDF)',
        Wm010RinseWaterTestReport::KEY => 'WM010 Rinse Water Test Sheet (PDF)',
        Wm001LabScalesCalibrationReport::KEY => 'WM001 Lab Scales Daily Calibration (PDF)',
        Wm002SaltMeterCalibrationReport::KEY => 'WM002 Daily Salt Meter Calibration (PDF)',
        Wm006ViscosityAutozeroReport::KEY => 'WM006 Viscosity Meter Autozero Check (PDF)',
        Wm013ProductionScalesCalibrationReport::KEY => 'WM013 Production Scales Daily Calibration (PDF)',
    ];

    /**
     * Scheduled reports the user still wants visible.
     * [report_key => report_name].
     */
    private const SCHEDULED_REPORTS = [
        DailyIntermediateProductionReport::KEY => 'Daily Wet Mustard - Manufacturing',
    ];

    public function run(): void
    {
        ReportRecipient::query()->whereIn('report_key', self::REMOVE_KEYS)->delete();
        ReportConfig::query()->whereIn('report_key', self::REMOVE_KEYS)->delete();

        foreach (self::SCHEDULED_REPORTS as $key => $name) {
            ReportConfig::updateOrCreate(
                ['report_key' => $key],
                [
                    'report_name' => $name,
                    'report_type' => 'scheduled',
                    'schedule_time' => '06:00',
                    'date_offset_from_days' => -1,
                    'date_offset_to_days' => -1,
                    'enabled' => true,
                ],
            );
        }

        foreach (self::REPORTS as $key => $name) {
            ReportConfig::updateOrCreate(
                ['report_key' => $key],
                [
                    'report_name' => $name,
                    'report_type' => 'on_demand',
                    'date_offset_from_days' => -30,
                    'date_offset_to_days' => 0,
                    'enabled' => true,
                ],
            );
        }
    }
}
