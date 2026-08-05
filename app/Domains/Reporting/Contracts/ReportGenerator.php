<?php

namespace App\Domains\Reporting\Contracts;

use Carbon\CarbonInterface;

/**
 * A DBMTS report generator. Each report accepts a date range and produces
 * self-contained HTML suitable for email (scope §14.7).
 */
interface ReportGenerator
{
    public function key(): string;

    public function name(): string;

    /**
     * @return array{subject: string, html: string, row_count: int, attachments?: array<int, string|array<string, string>>}
     */
    public function generate(CarbonInterface $from, CarbonInterface $to): array;
}
