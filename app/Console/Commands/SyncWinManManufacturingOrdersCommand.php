<?php

namespace App\Console\Commands;

use App\Domains\WinMan\Jobs\SyncOutstandingManufacturingOrdersJob;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refreshes the local winman_mo_sync_cache table so the MO Search screen has
 * a "last known good" fallback list when WinMan is unreachable (resilience
 * tier 2, see Documents/POC-Feature-Overview.md). Intended to be scheduled
 * externally (Windows Task Scheduler/cron) every 2-5 minutes, same pattern as
 * `dbmts:reports:run` - this app does not use Laravel's built-in scheduler.
 */
class SyncWinManManufacturingOrdersCommand extends Command
{
    protected $signature = 'winman:sync-manufacturing-orders {--limit=500 : Maximum outstanding MOs to cache}';

    protected $description = 'Sync outstanding WinMan manufacturing orders into a local fallback cache.';

    public function handle(SyncOutstandingManufacturingOrdersJob $sync): int
    {
        try {
            $count = $sync((int) $this->option('limit'));
        } catch (Throwable $e) {
            $this->error('WinMan MO sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($count === -1) {
            $this->comment('Skipped: another WinMan MO sync is already running.');

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->comment('WinMan returned no outstanding orders this run - local fallback cache left unchanged.');

            return self::SUCCESS;
        }

        $this->info("Synced {$count} outstanding manufacturing order(s) into the local fallback cache.");

        return self::SUCCESS;
    }
}
