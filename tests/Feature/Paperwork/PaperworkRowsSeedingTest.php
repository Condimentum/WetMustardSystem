<?php

namespace Tests\Feature\Paperwork;

use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Models\ManufacturingOrder;
use App\Models\PaperworkRow;
use App\Models\Product;
use App\Models\RecipeCard;
use App\Operations\SelectManufacturingOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaperworkRowsSeedingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSelection(ManufacturingOrder $order): void
    {
        $operation = Mockery::mock(SelectManufacturingOrderOperation::class);
        $operation->shouldReceive('__invoke')->andReturn($order);
        $this->instance(SelectManufacturingOrderOperation::class, $operation);
    }

    private function makeOrder(string $recipeCode, float $outstanding = 2000): ManufacturingOrder
    {
        $product = Product::create([
            'recipe_code' => $recipeCode,
            'product_name' => 'Test Mustard',
            'winman_product_id' => '50010007',
            'active_flag' => true,
        ]);

        return ManufacturingOrder::create([
            'mo_number' => 'MO-PAPER-001',
            'winman_manufacturing_order' => 777001,
            'winman_manufacturing_order_id' => 'MO-PAPER-001',
            'winman_product_id' => '50010007',
            'recipe_code' => $recipeCode,
            'product_id' => $product->id,
            'planned_quantity' => $outstanding,
            'quantity_outstanding' => $outstanding,
            'winman_classification' => 30,
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);
    }

    public function test_it_seeds_flat_paperwork_rows_when_batch_is_created(): void
    {
        RecipeCard::create([
            'recipe_code' => '30010001',
            'batch_size_kg' => 500,
            'revision_no' => 'R3',
            'steps' => ['Charge water', 'Add vinegar', 'Add mustard'],
        ]);

        $order = $this->makeOrder('30010001', 5000);
        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(777001);

        $this->assertDatabaseHas('paperwork_rows', [
            'batch_record_id' => $batch->id,
            'batch_column_index' => 1,
            'row_key' => 'header.batch_number',
            'value_text' => $batch->batch_number,
        ]);

        $this->assertDatabaseHas('paperwork_rows', [
            'batch_record_id' => $batch->id,
            'batch_column_index' => 1,
            'row_key' => 'header.batch_quantity_kg',
            'unit' => 'kg',
        ]);

        $this->assertDatabaseHas('paperwork_rows', [
            'batch_record_id' => $batch->id,
            'batch_column_index' => 1,
            'row_key' => 'recipe.step.001',
            'value_text' => 'Charge water',
        ]);
    }

    public function test_it_assigns_incrementing_batch_columns_for_same_mo(): void
    {
        RecipeCard::create([
            'recipe_code' => '30010001',
            'batch_size_kg' => 250,
            'steps' => ['Step A'],
        ]);

        $order = $this->makeOrder('30010001', 10000);
        $this->fakeSelection($order);

        app(StartBatchFromManufacturingOrderFeature::class)(777001);
        app(StartBatchFromManufacturingOrderFeature::class)(777001);
        app(StartBatchFromManufacturingOrderFeature::class)(777001);
        app(StartBatchFromManufacturingOrderFeature::class)(777001);

        $columns = PaperworkRow::query()
            ->where('manufacturing_order_id', $order->id)
            ->where('row_key', 'header.batch_number')
            ->orderBy('batch_column_index')
            ->pluck('batch_column_index')
            ->all();

        $this->assertSame([1, 2, 3, 4], array_map('intval', $columns));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
