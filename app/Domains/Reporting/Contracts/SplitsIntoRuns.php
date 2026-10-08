<?php

namespace App\Domains\Reporting\Contracts;

use Carbon\CarbonInterface;

/**
 * A report sent as one email per run (e.g. one batch card per MO) rather than
 * one email for the whole period. SendReportOperation sends each run key on
 * its own; run keys must resolve through ReportRegistry like any other key.
 */
interface SplitsIntoRuns
{
    /**
     * Report keys of the runs that fall in the period, e.g. ["doc_WM022@MO00006170"].
     *
     * @return array<int, string>
     */
    public function runKeys(CarbonInterface $from, CarbonInterface $to): array;

    /** True when this instance is already a single run (sent as-is, never split again). */
    public function isRun(): bool;
}
