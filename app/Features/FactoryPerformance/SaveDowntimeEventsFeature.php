<?php

namespace App\Features\FactoryPerformance;

use App\Domains\FactoryPerformance\Jobs\SaveDowntimeEventsJob;
use App\Domains\FactoryPerformance\Jobs\SetDowntimeSubmittedJob;

/** Saves (draft) or submits (locks) a day's whole downtime event list, as a full replace. */
class SaveDowntimeEventsFeature
{
    public function __invoke(int $departmentId, string $date, string $recordedBy, array $events, bool $submit): void
    {
        app(SaveDowntimeEventsJob::class)($departmentId, $date, $recordedBy, $events);
        app(SetDowntimeSubmittedJob::class)($departmentId, $date, $submit, $submit ? $recordedBy : null);
    }
}
