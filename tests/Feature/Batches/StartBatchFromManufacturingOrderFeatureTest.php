<?php

namespace Tests\Feature\Batches;

use App\Domains\Batch\Exceptions\BatchException;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\ProductMapping;
use App\Models\Product;
use App\Models\RecipeCard;
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

    private function makeOrder(?string $recipeCode, float $plannedQuantity = 1000, string $winmanProductId = '50010007'): ManufacturingOrder
    {
        $product = Product::create([
            'recipe_code' => $recipeCode ?? 'UNMAPPED',
            'product_name' => 'Test Mustard',
            'winman_product_id' => $winmanProductId,
            'active_flag' => true,
        ]);

        return ManufacturingOrder::create([
            'mo_number' => 'MO00000001',
            'winman_manufacturing_order' => 999001,
            'winman_manufacturing_order_id' => 'MO00000001',
            'winman_product_id' => $winmanProductId,
            'recipe_code' => $recipeCode,
            'product_id' => $product->id,
            'planned_quantity' => $plannedQuantity,
            'quantity_outstanding' => $plannedQuantity,
            'winman_classification' => 30,
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);
    }

    public function test_it_creates_a_batch_record_from_stored_recipe_batch_size(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 800);
        RecipeCard::create([
            'recipe_code' => 'R-NO-VARIANTS',
            'batch_size_kg' => 620,
        ]);
        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(999001);

        $this->assertInstanceOf(BatchRecord::class, $batch);
        $this->assertSame('MO00000001-50010007-01', $batch->batch_number);
        $this->assertSame('620.000', (string) $batch->planned_quantity);
        $this->assertSame(BatchRecord::STATUS_IN_PROGRESS, $batch->status);
    }

    public function test_batch_reference_sequence_increments_per_manufacturing_order(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 800);
        RecipeCard::create([
            'recipe_code' => 'R-NO-VARIANTS',
            'batch_size_kg' => 620,
        ]);
        $this->fakeSelection($order);

        $first = app(StartBatchFromManufacturingOrderFeature::class)(999001);
        $second = app(StartBatchFromManufacturingOrderFeature::class)(999001);

        $this->assertSame('MO00000001-50010007-01', $first->batch_number);
        $this->assertSame('MO00000001-50010007-02', $second->batch_number);
    }

    public function test_it_fails_when_recipe_batch_size_is_not_stored(): void
    {
        $order = $this->makeOrder('R-WITH-VARIANTS');
        $this->fakeSelection($order);

        $this->expectException(BatchException::class);
        $this->expectExceptionMessage('Recipe batch quantity is not stored for recipe R-WITH-VARIANTS');

        app(StartBatchFromManufacturingOrderFeature::class)(999001);
    }

    public function test_it_can_resolve_recipe_code_from_product_mapping_when_order_recipe_is_empty(): void
    {
        $order = $this->makeOrder(null, 1000, '70010026');

        ProductMapping::create([
            'structure_product' => 242,
            'structure_product_id' => '70010026',
            'structure_classification' => '29',
            'structure_product_description' => 'Finished Good',
            'component_product' => 241,
            'component_classification' => '30',
            'component_product_id' => '50010007',
            'component_product_description' => 'Intermediate',
            'quantity_per_unit' => 10.2,
            'structure_level' => 1,
        ]);

        ProductMapping::create([
            'structure_product' => 241,
            'structure_product_id' => '50010007',
            'structure_classification' => '30',
            'structure_product_description' => 'Intermediate',
            'component_product' => 307,
            'component_classification' => '30',
            'component_product_id' => '30010001',
            'component_product_description' => 'Recipe',
            'quantity_per_unit' => 1.05,
            'structure_level' => 2,
        ]);

        RecipeCard::create([
            'recipe_code' => '30010001',
            'batch_size_kg' => 800,
        ]);

        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(999001);

        $this->assertSame('800.000', (string) $batch->planned_quantity);
    }

    public function test_it_allows_optional_variant_but_still_uses_recipe_batch_size(): void
    {
        $order = $this->makeOrder('R-WITH-VARIANTS', 1000);
        $variant = RecipeVariant::create([
            'recipe_code' => 'R-WITH-VARIANTS',
            'variant_name' => '500kg',
            'batch_size' => 500,
            'active_flag' => true,
        ]);
        RecipeCard::create([
            'recipe_code' => 'R-WITH-VARIANTS',
            'batch_size_kg' => 700,
        ]);
        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(999001, $variant->id);

        $this->assertSame((int) $variant->id, (int) $batch->variant_id);
        $this->assertSame('700.000', (string) $batch->planned_quantity);
    }

    public function test_it_rejects_recipe_batch_quantity_above_mo_outstanding(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 1000);
        $order->update(['quantity_outstanding' => 300]);
        RecipeCard::create([
            'recipe_code' => 'R-NO-VARIANTS',
            'batch_size_kg' => 350,
        ]);
        $this->fakeSelection($order->fresh());

        $this->expectException(BatchException::class);

        app(StartBatchFromManufacturingOrderFeature::class)(999001);
    }

    public function test_it_rejects_non_intermediate_classification(): void
    {
        $order = $this->makeOrder('R-NO-VARIANTS', 1000);
        $order->update(['winman_classification' => 29]);
        RecipeCard::create([
            'recipe_code' => 'R-NO-VARIANTS',
            'batch_size_kg' => 250,
        ]);
        $this->fakeSelection($order->fresh());

        $this->expectException(BatchException::class);

        app(StartBatchFromManufacturingOrderFeature::class)(999001);
    }

    public function test_it_requires_batch_size_selection_when_recipe_has_multiple_sizes(): void
    {
        $order = $this->makeOrder('R-MULTI-SIZE', 1000);
        RecipeCard::create([
            'recipe_code' => 'R-MULTI-SIZE',
            'batch_size_kg' => 500,
            'batch_sizes_kg' => [500, 750],
        ]);
        $this->fakeSelection($order);

        $this->expectException(BatchException::class);
        $this->expectExceptionMessage('Multiple batch sizes are configured for recipe R-MULTI-SIZE');

        app(StartBatchFromManufacturingOrderFeature::class)(999001);
    }

    public function test_it_uses_selected_batch_size_when_recipe_has_multiple_sizes(): void
    {
        $order = $this->makeOrder('R-MULTI-SIZE', 1000);
        RecipeCard::create([
            'recipe_code' => 'R-MULTI-SIZE',
            'batch_size_kg' => 500,
            'batch_sizes_kg' => [500, 750],
        ]);
        $this->fakeSelection($order);

        $batch = app(StartBatchFromManufacturingOrderFeature::class)(999001, null, 750.0);

        $this->assertSame('750.000', (string) $batch->planned_quantity);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
