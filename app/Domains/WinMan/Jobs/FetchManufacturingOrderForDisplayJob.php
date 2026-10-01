<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Support\WinManConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Display-only cached wrapper around FetchManufacturingOrderJob.
 *
 * Screens that merely SHOW an MO's details (batch workspace header, MO
 * workspace mount) don't need a fresh network round trip on every single
 * page view/reload. Selection and pre-booking concurrency checks must stay
 * authoritative and should keep calling FetchManufacturingOrderJob directly.
 */
class FetchManufacturingOrderForDisplayJob
{
    public function __construct(
        private readonly FetchManufacturingOrderJob $fetch,
        private readonly WinManConnection $winman,
    ) {
    }

    public function __invoke(int $winmanManufacturingOrder): ?ManufacturingOrderData
    {
        $ttl = (int) config('winman.resilience.mo_display_cache_seconds', 20);

        if ($ttl <= 0) {
            return ($this->fetch)($winmanManufacturingOrder);
        }

        $cacheKey = sprintf('winman:mo-display:%s:%d', $this->winman->environment(), $winmanManufacturingOrder);

        return Cache::remember($cacheKey, $ttl, fn (): ?ManufacturingOrderData => ($this->fetch)($winmanManufacturingOrder));
    }
}
