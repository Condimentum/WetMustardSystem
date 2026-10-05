<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptDowntimeReason;
use Illuminate\Support\Collection;

/** Downtime reason dropdown options for one department (plus the shared global ones). */
class FetchDowntimeReasonsJob
{
    public function __invoke(int $departmentId): Collection
    {
        return FptDowntimeReason::query()
            ->where('IsActive', true)
            ->where(function ($query) use ($departmentId) {
                $query->where('DepartmentId', $departmentId)->orWhereNull('DepartmentId');
            })
            ->orderBy('DisplayOrder')
            ->get();
    }
}
