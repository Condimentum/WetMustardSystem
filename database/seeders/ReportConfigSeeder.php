<?php

namespace Database\Seeders;

use App\Domains\Reporting\Reports\ActiveMasterDataReport;
use App\Domains\Reporting\Reports\BatchRecordSummaryReport;
use App\Domains\Reporting\Reports\DailyIntermediateProductionReport;
use App\Domains\Reporting\Reports\DailyProductionSummaryReport;
use App\Domains\Reporting\Reports\DrumProcessingSummaryReport;
use App\Domains\Reporting\Reports\OpenBatchesReport;
use App\Domains\Reporting\Reports\OverdueMetalDetectorReport;
use App\Domains\Reporting\Reports\PackingWeightExceptionsReport;
use App\Domains\Reporting\Reports\QaApprovalQueueReport;
use App\Domains\Reporting\Reports\TraceabilityExceptionsReport;
use App\Models\ReportConfig;
use Illuminate\Database\Seeder;

/**
 * Seeds the scheduled DBMTS report configurations (scope §14.1). Idempotent.
 */
class ReportConfigSeeder extends Seeder
{
    /**
     * [report_key => report_name].
     */
    private const REPORTS = [
        DailyProductionSummaryReport::KEY => 'Daily Production Summary',
        DailyIntermediateProductionReport::KEY => 'Daily Intermediate Production',
        OpenBatchesReport::KEY => 'Open / Incomplete Batch Records',
        OverdueMetalDetectorReport::KEY => 'Overdue Metal Detector Checks',
        PackingWeightExceptionsReport::KEY => 'Packing Weight Exceptions',
        DrumProcessingSummaryReport::KEY => 'Drum Processing Daily Summary',
        QaApprovalQueueReport::KEY => 'Records Awaiting QA Approval',
        TraceabilityExceptionsReport::KEY => 'Traceability Exceptions',
    ];

    /**
     * On-demand reports (scope §14.2) - registered but not auto-scheduled.
     * [report_key => report_name].
     */
    private const ON_DEMAND = [
        ActiveMasterDataReport::KEY => 'Active Products, Recipes and Variants',
        BatchRecordSummaryReport::KEY => 'Batch Record Summary by Date Range',
    ];

    public function run(): void
    {
        foreach (self::REPORTS as $key => $name) {
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

        foreach (self::ON_DEMAND as $key => $name) {
            ReportConfig::updateOrCreate(
                ['report_key' => $key],
                [
                    'report_name' => $name,
                    'report_type' => 'on_demand',
                    'date_offset_from_days' => -30,
                    'date_offset_to_days' => 0,
                    'enabled' => false,
                ],
            );
        }
    }
}
