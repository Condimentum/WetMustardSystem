<?php

namespace Tests\Feature\WinMan;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Jobs\SearchOutstandingManufacturingOrdersJob;
use App\Domains\WinMan\Jobs\SyncOutstandingManufacturingOrdersJob;
use App\Models\WinManSyncedManufacturingOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SyncOutstandingManufacturingOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSearch(array $orders): void
    {
        $search = Mockery::mock(SearchOutstandingManufacturingOrdersJob::class);
        $search->shouldReceive('__invoke')->andReturn($orders);
        $this->instance(SearchOutstandingManufacturingOrdersJob::class, $search);
    }

    private function order(int $mo, float $outstanding = 100.0): ManufacturingOrderData
    {
        return new ManufacturingOrderData(
            winmanManufacturingOrder: $mo,
            winmanManufacturingOrderId: 'MO'.$mo,
            winmanProductInternal: 1,
            winmanProductId: '30010001',
            productDescription: 'Test Product',
            systemType: 'F',
            plannedQuantity: 500.0,
            quantityOutstanding: $outstanding,
            classification: 30,
            unitOfMeasure: 2,
            unitOfMeasureDescription: 'KG',
            dueDate: '2026-09-10',
            lastModifiedDate: '2026-09-01 00:00:00',
        );
    }

    public function test_it_upserts_orders_into_the_local_cache(): void
    {
        $this->fakeSearch([$this->order(1), $this->order(2)]);

        $count = app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->assertSame(2, $count);
        $this->assertSame(2, WinManSyncedManufacturingOrder::count());
    }

    public function test_a_second_run_updates_instead_of_duplicating(): void
    {
        $this->fakeSearch([$this->order(1, outstanding: 100.0)]);
        app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->fakeSearch([$this->order(1, outstanding: 42.0)]);
        app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->assertSame(1, WinManSyncedManufacturingOrder::count());
        $this->assertSame(42.0, (float) WinManSyncedManufacturingOrder::first()->quantity_outstanding);
    }

    public function test_it_removes_mos_that_are_no_longer_outstanding(): void
    {
        $this->fakeSearch([$this->order(1), $this->order(2)]);
        app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->fakeSearch([$this->order(1)]);
        app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->assertSame(1, WinManSyncedManufacturingOrder::count());
        $this->assertDatabaseHas('winman_mo_sync_cache', ['winman_manufacturing_order' => 1]);
        $this->assertDatabaseMissing('winman_mo_sync_cache', ['winman_manufacturing_order' => 2]);
    }

    public function test_an_empty_winman_response_does_not_wipe_the_existing_cache(): void
    {
        $this->fakeSearch([$this->order(1)]);
        app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->fakeSearch([]);
        $count = app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->assertSame(0, $count);
        $this->assertSame(1, WinManSyncedManufacturingOrder::count());
    }

    public function test_a_concurrent_run_is_skipped_instead_of_racing(): void
    {
        $lock = Cache::lock('winman:mo-sync-cache:lock', 240);
        $this->assertTrue($lock->get());

        $search = Mockery::mock(SearchOutstandingManufacturingOrdersJob::class);
        $search->shouldReceive('__invoke')->never();
        $this->instance(SearchOutstandingManufacturingOrdersJob::class, $search);

        $count = app(SyncOutstandingManufacturingOrdersJob::class)();

        $this->assertSame(-1, $count);
        $lock->release();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
