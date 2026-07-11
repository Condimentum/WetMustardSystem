<?php

namespace Tests\Feature\Batches;

use App\Domains\Batch\Exceptions\BatchException;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\RecipeVariant;
use App\Operations\SelectManufacturingOrderOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StartBatchFromManufacturingOrderFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSelection(ManufacturingOrder $order): void
    {
        $operation = Mockery::mock(SelectManufacturingOrderOperation::class);
        $operation->shouldReceive('__invoke')->andReturn($order);
        $this->instance(SelectManufacturingOrderOperation::class, $operation);
    }

    private function makeOrder(string $recipeCode, float $plannedQuantity = 1000): ManufacturingOrder
    {
        $product = Product::create([
            'recipe_code' => $recipeCode,
            'product_name' => 'Test Mustard',
            'active_flag' => true,
        ]);

        return ManufacturingOrder::create([
            'mo_number' => 'MO00000001',
            'winman_manufacturing_order' => 999001,
            'winman_manufacturing_order_id' => 'MO00000001',
            'recipe_code' => $recipeCode,
            'product_id' => $product->id,
            'planned_quantity' => $plannedQuantity,
            'quantity_outstanding' => $plannedQuantity,
            'winman_classification' => 30,
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);
    }

    public function test_it_creates_a_batch_record_without_a_variant_when_recipe_has_none(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 800);
        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(999001);

        $this->assertInstanceOf(BatchRecord::class, $batch);
        $this->assertMatchesRegularExpression('/^WM\d{6}-\d{2}$/', $batch->batch_number);
        $this->assertSame('800.000', (string) $batch->planned_quantity);
        $this->assertSame(BatchRecord::STATUS_IN_PROGRESS, $batch->status);
    }

    public function test_it_requires_a_variant_when_recipe_has_active_variants(): void
    {
        $order = $this->makeOrder('R-WITH-VARIANTS');
        RecipeVariant::create([
            'recipe_code' => 'R-WITH-VARIANTS',
            'variant_name' => '500kg',
            'batch_size' => 500,
            'active_flag' => true,
        ]);
        $this->fakeSelection($order);

        $this->expectException(BatchException::class);

        app(StartBatchFromManufacturingOrderFeature::class)(999001);
    }

    public function test_it_creates_a_batch_with_the_selected_variant_quantity(): void
    {
        $order = $this->makeOrder('R-WITH-VARIANTS');
        $variant = RecipeVariant::create([
            'recipe_code' => 'R-WITH-VARIANTS',
            'variant_name' => '500kg',
            'batch_size' => 500,
            'active_flag' => true,
        ]);
        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(999001, $variant->id);

        $this->assertSame($variant->id, $batch->variant_id);
        $this->assertSame('500.000', (string) $batch->planned_quantity);
        $this->assertSame($variant->id, $order->fresh()->variant_id);
    }

    public function test_it_allows_partial_batch_quantity_when_requested(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 1000);
        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(999001, null, 250.0);

        $this->assertSame('250.000', (string) $batch->planned_quantity);
    }

    public function test_it_rejects_batch_quantity_above_mo_outstanding(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 1000);
        $order->update(['quantity_outstanding' => 300]);
        $this->fakeSelection($order->fresh());

        $this->expectException(BatchException::class);

        app(StartBatchFromManufacturingOrderFeature::class)(999001, null, 350.0);
    }

    public function test_it_rejects_non_intermediate_classification(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 1000);
        $order->update(['winman_classification' => 29]);
        $this->fakeSelection($order->fresh());

        $this->expectException(BatchException::class);

        app(StartBatchFromManufacturingOrderFeature::class)(999001, null, 250.0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
