<?php

namespace App\Domains\Reporting;

use App\Domains\Reporting\Contracts\ReportGenerator;
use App\Domains\Reporting\Reports\ActiveMasterDataReport;
use App\Domains\Reporting\Reports\BatchRecordSummaryReport;
use App\Domains\Reporting\Reports\Wm001LabScalesCalibrationReport;
use App\Domains\Reporting\Reports\Wm002SaltMeterCalibrationReport;
use App\Domains\Reporting\Reports\Wm006ViscosityAutozeroReport;
use App\Domains\Reporting\Reports\Wm013ProductionScalesCalibrationReport;
use App\Domains\Reporting\Reports\DailyIntermediateProductionReport;
use App\Domains\Reporting\Reports\DailyProductionSummaryReport;
use App\Domains\Reporting\Reports\DrumProcessingSummaryReport;
use App\Domains\Reporting\Reports\MetalDetectorVerificationSheetReport;
use App\Domains\Reporting\Reports\OpenBatchesReport;
use App\Domains\Reporting\Reports\OverdueMetalDetectorReport;
use App\Domains\Reporting\Reports\PackingWeightExceptionsReport;
use App\Domains\Reporting\Reports\QaApprovalQueueReport;
use App\Domains\Reporting\Reports\TraceabilityExceptionsReport;
use App\Domains\Reporting\Reports\Wm003IbcTraceabilityReport;
use App\Domains\Reporting\Reports\Wm005WetMustardLabTestingReport;
use App\Domains\Reporting\Reports\Wm010RinseWaterTestReport;

/**
 * Registry of whitelisted DBMTS report generators. Report keys must be
 * registered here before manual or scheduled execution (scope §11 validation).
 */
class ReportRegistry
{
    /**
     * @var array<string, class-string<ReportGenerator>>
     */
    private array $map = [
        DailyProductionSummaryReport::KEY => DailyProductionSummaryReport::class,
        DailyIntermediateProductionReport::KEY => DailyIntermediateProductionReport::class,
        MetalDetectorVerificationSheetReport::KEY => MetalDetectorVerificationSheetReport::class,
        OpenBatchesReport::KEY => OpenBatchesReport::class,
        OverdueMetalDetectorReport::KEY => OverdueMetalDetectorReport::class,
        PackingWeightExceptionsReport::KEY => PackingWeightExceptionsReport::class,
        DrumProcessingSummaryReport::KEY => DrumProcessingSummaryReport::class,
        QaApprovalQueueReport::KEY => QaApprovalQueueReport::class,
        TraceabilityExceptionsReport::KEY => TraceabilityExceptionsReport::class,
        ActiveMasterDataReport::KEY => ActiveMasterDataReport::class,
        BatchRecordSummaryReport::KEY => BatchRecordSummaryReport::class,
        Wm001LabScalesCalibrationReport::KEY => Wm001LabScalesCalibrationReport::class,
        Wm002SaltMeterCalibrationReport::KEY => Wm002SaltMeterCalibrationReport::class,
        Wm006ViscosityAutozeroReport::KEY => Wm006ViscosityAutozeroReport::class,
        Wm013ProductionScalesCalibrationReport::KEY => Wm013ProductionScalesCalibrationReport::class,
        Wm003IbcTraceabilityReport::KEY => Wm003IbcTraceabilityReport::class,
        Wm005WetMustardLabTestingReport::KEY => Wm005WetMustardLabTestingReport::class,
        Wm010RinseWaterTestReport::KEY => Wm010RinseWaterTestReport::class,
    ];

    public function has(string $key): bool
    {
        return isset($this->map[$key]);
    }

    public function get(string $key): ?ReportGenerator
    {
        return $this->has($key) ? app($this->map[$key]) : null;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->map);
    }
}
