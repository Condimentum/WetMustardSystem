<?php

namespace Tests\Feature\ManufacturingOrders;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconFill;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class WorkspaceAllocationColumnTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeWinManOrder(int $winmanMo): void
    {
        $health = Mockery::mock(WinManHealthCheck::class);
        $health->shouldReceive('isUp')->andReturn(true);
        $this->instance(WinManHealthCheck::class, $health);

        $job = Mockery::mock(FetchManufacturingOrderJob::class);
        $job->shouldReceive('__invoke')->andReturn(new ManufacturingOrderData(
            winmanManufacturingOrder: $winmanMo,
            winmanManufacturingOrderId: 'MO'.$winmanMo,
            winmanProductInternal: 1,
            winmanProductId: '50010007',
            productDescription: 'Test Mustard',
            systemType: 'F',
            plannedQuantity: 800,
            quantityOutstanding: 800,
            classification: 30,
            unitOfMeasure: 2,
            unitOfMeasureDescription: 'PALLECON',
            dueDate: null,
            lastModifiedDate: null,
        ));
        $this->instance(FetchManufacturingOrderJob::class, $job);
    }

    public function test_allocation_column_reflects_pallecon_fill_progress(): void
    {
        $winmanMo = 7100;
        $this->fakeWinManOrder($winmanMo);
        $this->actingAs(User::factory()->create());

        $product = Product::create(['recipe_code' => 'RALLOC', 'product_name' => 'Test Mustard', 'winman_product_id' => '50010007', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MO7100', 'winman_manufacturing_order' => $winmanMo, 'winman_manufacturing_order_id' => 'MO7100',
            'winman_product_id' => '50010007', 'recipe_code' => 'RALLOC', 'product_id' => $product->id,
            'planned_quantity' => 800, 'quantity_outstanding' => 800, 'winman_system_type' => 'F', 'status' => 'selected',
        ]);

        $makeBatch = function (string $ref) use ($order, $product): BatchRecord {
            return BatchRecord::create([
                'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => $ref,
                'production_date' => now()->toDateString(), 'planned_quantity' => 800, 'status' => BatchRecord::STATUS_COMPLETED,
            ]);
        };

        $awaiting = $makeBatch('WM-ALLOC-A');
        $partial = $makeBatch('WM-ALLOC-B');
        $full = $makeBatch('WM-ALLOC-C');

        $pallecon = Pallecon::create(['serial_number' => 'PAL-ALLOC-1', 'status' => 'open']);
        PalleconFill::create(['pallecon_id' => $pallecon->id, 'batch_record_id' => $partial->id, 'fill_weight' => 300, 'sequence' => 1]);
        PalleconFill::create(['pallecon_id' => $pallecon->id, 'batch_record_id' => $full->id, 'fill_weight' => 500, 'sequence' => 2]);
        PalleconFill::create(['pallecon_id' => $pallecon->id, 'batch_record_id' => $full->id, 'fill_weight' => 300, 'sequence' => 3]);

        Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => $winmanMo])
            ->assertOk()
            ->assertSee('Allocation')
            ->assertSeeInOrder([
                'WM-ALLOC-A', 'Awaiting Allocation',
                'WM-ALLOC-B', 'Partially Allocated',
                'WM-ALLOC-C', 'Allocated',
            ])
            ->assertSee(route('manufacturing-orders.pallecons', ['winmanMo' => $winmanMo]), escape: false);
    }
}
