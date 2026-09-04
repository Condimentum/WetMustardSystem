<?php

namespace App\Domains\WinMan\Jobs;

use App\Models\WinManSyncedManufacturingOrder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes the local winman_mo_sync_cache table from a live WinMan query, so
 * the MO Search screen has a "last known good" list to fall back to when
 * WinMan itself is unreachable (resilience tier 2). Not authoritative; the
 * live query is always preferred when WinMan is up.
 *
 * Hardened against overlapping/duplicate runs (e.g. a Task Scheduler retry
 * firing while the previous sync is still running, or someone triggering it
 * manually at the same time): a process lock prevents two syncs writing at
 * once, and the write itself is an upsert (never a truncate) so a returning
 * duplicate row never crashes on the unique constraint and the cache is never
 * briefly empty mid-refresh.
 */
class SyncOutstandingManufacturingOrdersJob
{
    private const LOCK_KEY = 'winman:mo-sync-cache:lock';

    private const LOCK_SECONDS = 240;

    public function __construct(
        private readonly SearchOutstandingManufacturingOrdersJob $searchOrders,
    ) {
    }

    /**
     * @return int Rows synced; 0 if WinMan returned nothing (cache left untouched
     *             on purpose); -1 if skipped because another sync was already running.
     */
    public function __invoke(int $limit = 500): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return -1;
        }

        try {
            return $this->sync($limit);
        } finally {
            $lock->release();
        }
    }

    private function sync(int $limit): int
    {
        $orders = ($this->searchOrders)(null, $limit);

        // A glitchy/empty WinMan response must never wipe out a previously
        // good fallback snapshot - skip the write entirely rather than risk
        // leaving MO Search with nothing to fall back on.
        if ($orders === []) {
            return 0;
        }

        $now = now();

        $rows = array_map(static fn ($order): array => [
            'winman_manufacturing_order' => $order->winmanManufacturingOrder,
            'winman_manufacturing_order_id' => $order->winmanManufacturingOrderId,
            'winman_product_internal' => $order->winmanProductInternal,
            'winman_product_id' => $order->winmanProductId,
            'product_description' => $order->productDescription,
            'system_type' => $order->systemType,
            'planned_quantity' => $order->plannedQuantity,
            'quantity_outstanding' => $order->quantityOutstanding,
            'classification' => $order->classification,
            'unit_of_measure' => $order->unitOfMeasure,
            'unit_of_measure_description' => $order->unitOfMeasureDescription,
            'due_date' => $order->dueDate,
            'last_modified_date' => $order->lastModifiedDate,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $orders);

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                WinManSyncedManufacturingOrder::query()->upsert(
                    $chunk,
                    ['winman_manufacturing_order'],
                    [
                        'winman_manufacturing_order_id', 'winman_product_internal', 'winman_product_id',
                        'product_description', 'system_type', 'planned_quantity', 'quantity_outstanding',
                        'classification', 'unit_of_measure', 'unit_of_measure_description', 'due_date',
                        'last_modified_date', 'synced_at', 'updated_at',
                    ],
                );
            }

            // Drop MOs that are no longer outstanding (present before, gone now).
            // Only reached when $rows is non-empty, so this can never wipe the
            // whole table via an accidental whereNotIn([]) match-all.
            WinManSyncedManufacturingOrder::query()
                ->whereNotIn('winman_manufacturing_order', array_column($rows, 'winman_manufacturing_order'))
                ->delete();
        });

        return count($rows);
    }
}
