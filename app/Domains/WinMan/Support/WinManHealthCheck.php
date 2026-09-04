<?php

namespace App\Domains\WinMan\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cheap, cached "is WinMan reachable right now" probe so screens can skip a
 * doomed live query (and its connection timeout) instead of hanging on every
 * request during a WinMan outage (resilience tier 1, see
 * Documents/POC-Feature-Overview.md).
 */
class WinManHealthCheck
{
    public function __construct(
        private readonly WinManConnection $winman,
    ) {
    }

    public function isUp(): bool
    {
        $ttl = (int) config('winman.resilience.health_check_cache_seconds', 15);

        if ($ttl <= 0) {
            return $this->probe();
        }

        return (bool) Cache::remember($this->cacheKey(), $ttl, fn (): bool => $this->probe());
    }

    private function cacheKey(): string
    {
        return 'winman:health:'.$this->winman->environment();
    }

    private function probe(): bool
    {
        try {
            $this->winman->connection()->select('SELECT 1 AS ok');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
