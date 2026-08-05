<?php

namespace App\Domains\Reporting;

use App\Domains\Reporting\Contracts\ReportGenerator;
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
        OpenBatchesReport::KEY => OpenBatchesReport::class,
        OverdueMetalDetectorReport::KEY => OverdueMetalDetectorReport::class,
        PackingWeightExceptionsReport::KEY => PackingWeightExceptionsReport::class,
        DrumProcessingSummaryReport::KEY => DrumProcessingSummaryReport::class,
        QaApprovalQueueReport::KEY => QaApprovalQueueReport::class,
        TraceabilityExceptionsReport::KEY => TraceabilityExceptionsReport::class,
        ActiveMasterDataReport::KEY => ActiveMasterDataReport::class,
        BatchRecordSummaryReport::KEY => BatchRecordSummaryReport::class,
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
