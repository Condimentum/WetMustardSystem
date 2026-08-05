<?php

namespace Tests\Feature\Batches;

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\ManufacturingOrder\Jobs\StoreComponentSnapshotJob;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderComponentsJob;
use App\Domains\WinMan\Jobs\IssueWorkInProgressFromLotJob;
use App\Models\BatchIngredientLot;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\WinManIssueLog;
use App\Models\WinManMoComponentSnapshot;
use App\Operations\AllocateBomIngredientOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AllocateBomIngredientOperationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBatchWithComponent(): array
    {
        $product = Product::create([
            'recipe_code' => '30010001',
            'product_name' => 'Test Mustard',
            'active_flag' => true,
        ]);

        $order = ManufacturingOrder::create([
            'mo_number' => 'MO-ALLOC-001',
            'winman_manufacturing_order' => 900001,
            'winman_manufacturing_order_id' => 'MO-ALLOC-001',
            'recipe_code' => '30010001',
            'product_id' => $product->id,
            'planned_quantity' => 1000,
            'quantity_outstanding' => 1000,
            'winman_classification' => 30,
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);

        $batch = BatchRecord::create([
            'manufacturing_order_id' => $order->id,
            'product_id' => $product->id,
            'batch_number' => 'WM260728-01',
            'production_date' => now()->toDateString(),
            'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);

        $component = WinManMoComponentSnapshot::create([
            'manufacturing_order_id' => $order->id,
            'winman_manufacturing_order' => 900001,
            'winman_work_in_progress' => 50001,
            'item_type' => 'C',
            'winman_component_product' => '100018',
            'winman_component_product_id' => '100018',
            'component_description' => 'Spirit Vinegar 14%',
            'classification' => '30',
            'quantity' => 200,
            'quantity_issued' => 0,
            'quantity_outstanding' => 200,
            'snapshot_at' => now(),
        ]);

        return [$batch, $component, $order];
    }

    public function test_it_records_partial_issue_when_lot_has_less_than_requested(): void
    {
        [$batch, $component, $order] = $this->makeBatchWithComponent();
        $user = User::factory()->create();

        $issueJob = Mockery::mock(IssueWorkInProgressFromLotJob::class);
        $issueJob->shouldReceive('__invoke')
            ->once()
            ->andReturn([
                'issued_quantity' => 114.35314,
                'issued_inventory_ids' => [12345],
            ]);
        $this->instance(IssueWorkInProgressFromLotJob::class, $issueJob);

        $fetchComponents = Mockery::mock(FetchManufacturingOrderComponentsJob::class);
        $fetchComponents->shouldReceive('__invoke')->once()->with(900001)->andReturn([]);
        $this->instance(FetchManufacturingOrderComponentsJob::class, $fetchComponents);

        $storeSnapshot = Mockery::mock(StoreComponentSnapshotJob::class);
        $storeSnapshot->shouldReceive('__invoke')->once()->withArgs(function ($selectedOrder, $components): bool {
            return $selectedOrder instanceof ManufacturingOrder && is_array($components);
        });
        $this->instance(StoreComponentSnapshotJob::class, $storeSnapshot);

        $lot = app(AllocateBomIngredientOperation::class)(
            $batch,
            $component,
            '8000263-0010',
            144.44,
            $user,
        );

        $this->assertInstanceOf(BatchIngredientLot::class, $lot);
        $this->assertSame('114.353', (string) $lot->actual_quantity);

        $this->assertDatabaseHas('winman_issue_logs', [
            'batch_record_id' => $batch->id,
            'component_snapshot_id' => $component->id,
            'lot_number' => '8000263-0010',
            'issue_status' => WinManIssueLog::STATUS_SUCCESS,
            'quantity_issued' => 114.35314,
        ]);

        $this->assertDatabaseCount('batch_ingredient_lots', 1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
