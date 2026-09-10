<?php

namespace Tests\Feature\ManufacturingOrders;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\RecipeCard;
use App\Models\User;
use App\Operations\SelectManufacturingOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class WorkspaceAddBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function setUpMo(int $winmanMo): ManufacturingOrder
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

        $product = Product::create(['recipe_code' => 'RADD', 'product_name' => 'Test Mustard', 'winman_product_id' => '50010007', 'active_flag' => true]);
        RecipeCard::create(['recipe_code' => 'RADD', 'batch_size_kg' => 400]);

        return ManufacturingOrder::create([
            'mo_number' => 'MO'.$winmanMo, 'winman_manufacturing_order' => $winmanMo, 'winman_manufacturing_order_id' => 'MO'.$winmanMo,
            'winman_product_id' => '50010007', 'recipe_code' => 'RADD', 'product_id' => $product->id,
            'planned_quantity' => 800, 'quantity_outstanding' => 800, 'winman_classification' => 30,
            'winman_system_type' => 'F', 'status' => 'selected',
        ]);
    }

    private function makeBatch(ManufacturingOrder $order, string $ref, string $status): BatchRecord
    {
        return BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $order->product_id, 'batch_number' => $ref,
            'production_date' => now()->toDateString(), 'planned_quantity' => 400, 'status' => $status,
        ]);
    }

    public function test_add_another_batch_is_offered_once_the_previous_batch_is_completed(): void
    {
        $order = $this->setUpMo(7200);
        $this->makeBatch($order, 'WM-ADD-01', BatchRecord::STATUS_COMPLETED);
        $this->actingAs(User::factory()->create());

        $selection = Mockery::mock(SelectManufacturingOrderOperation::class);
        $selection->shouldReceive('__invoke')->andReturn($order);
        $this->instance(SelectManufacturingOrderOperation::class, $selection);

        $component = Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7200])
            ->assertOk()
            ->assertSee('Add another batch')
            ->assertDontSee('Complete the in-progress batch');

        $component->call('start')->assertHasNoErrors();

        $this->assertSame(2, BatchRecord::where('manufacturing_order_id', $order->id)->count());
    }

    public function test_add_batch_is_hidden_while_a_batch_is_in_progress(): void
    {
        $order = $this->setUpMo(7201);
        $this->makeBatch($order, 'WM-ADD-02', BatchRecord::STATUS_IN_PROGRESS);
        $this->actingAs(User::factory()->create());

        Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7201])
            ->assertOk()
            ->assertSee('Complete the in-progress batch before adding another one to this MO.')
            ->assertDontSee('Add another batch')
            ->call('start')
            ->assertSet('error', 'Complete the current in-progress batch before adding another batch.');

        $this->assertSame(1, BatchRecord::where('manufacturing_order_id', $order->id)->count());
    }
}
