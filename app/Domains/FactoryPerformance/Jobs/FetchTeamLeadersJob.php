<?php

namespace App\Domains\FactoryPerformance\Jobs;

use App\Models\FptTeamLeader;
use Illuminate\Support\Collection;

/** "Start By" / "End By" name dropdown options for one department. */
class FetchTeamLeadersJob
{
    public function __invoke(int $departmentId): Collection
    {
        return FptTeamLeader::query()
            ->where('DepartmentId', $departmentId)
            ->where('IsActive', true)
            ->orderBy('TeamLeaderName')
            ->get();
    }
}
