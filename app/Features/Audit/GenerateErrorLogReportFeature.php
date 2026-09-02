<?php

namespace App\Features\Audit;

use App\Models\ErrorLog;
use Illuminate\Support\Collection;

/**
 * Returns filtered error log entries for the Error Log "event viewer" report.
 */
class GenerateErrorLogReportFeature
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ErrorLog>
     */
    public function __invoke(array $filters): Collection
    {
        return ErrorLog::query()
            ->with('user')
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
            ->when($filters['context'] ?? null, fn ($q, $v) => $q->where('context', 'like', '%'.$v.'%'))
            ->latest('id')
            ->limit(500)
            ->get();
    }
}
