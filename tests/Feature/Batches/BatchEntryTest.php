<?php

namespace Tests\Feature\Batches;

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Batch\Jobs\ValidateBatchCompletionJob;
use App\Features\Batches\AddIngredientLotFeature;
use App\Features\Batches\CompleteBatchFeature;
use App\Features\Batches\SignIngredientLotFeature;
use App\Models\BatchIngredientLot;
use App\Models\BatchRecord;
use App\Models\ElectronicSignature;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchEntryTest extends TestCase
{
    use RefreshDatabase;

    private function makeBatch(): BatchRecord
    {
        $product = Product::create([
            'recipe_code' => 'R1',
            'product_name' => 'Test Mustard',
            'active_flag' => true,
        ]);

        $order = ManufacturingOrder::create([
            'mo_number' => 'MO1',
            'winman_manufacturing_order' => 111,
            'winman_manufacturing_order_id' => 'MO1',
            'recipe_code' => 'R1',
            'product_id' => $product->id,
            'planned_quantity' => 500,
            'quantity_outstanding' => 500,
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);

        return BatchRecord::create([
            'manufacturing_order_id' => $order->id,
            'product_id' => $product->id,
            'batch_number' => 'WM260708-99',
            'production_date' => now()->toDateString(),
            'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_completion_is_blocked_until_lots_are_added_and_signed(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch();

        // No lots yet -> blocked.
        try {
            app(CompleteBatchFeature::class)($batch, $user);
            $this->fail('Expected BatchException.');
        } catch (BatchException $e) {
            $this->assertContains('At least one ingredient lot must be recorded.', $e->issues);
        }

        // Add a lot; still missing sign-offs.
        app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Spirit Vinegar 14%',
            'lot_number' => 'LOT-001',
            'actual_quantity' => 655.2,
            'uom' => 'kg',
        ], $user);

        $issues = app(ValidateBatchCompletionJob::class)($batch->fresh());
        $this->assertContains("Ingredient 'Spirit Vinegar 14%' is missing a weighed sign-off.", $issues);
        $this->assertContains("Ingredient 'Spirit Vinegar 14%' is missing a tipped sign-off.", $issues);

        $lot = $batch->ingredientLots()->first();
        app(SignIngredientLotFeature::class)($lot, 'weighed', $user);
        app(SignIngredientLotFeature::class)($lot->fresh(), 'tipped', $user);

        $this->assertSame([], app(ValidateBatchCompletionJob::class)($batch->fresh()));
    }

    public function test_completing_a_valid_batch_records_signatures_and_status(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch();

        $lot = app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Water',
            'lot_number' => 'LOT-W',
            'actual_quantity' => 100,
            'uom' => 'kg',
        ], $user);
        app(SignIngredientLotFeature::class)($lot, 'weighed', $user);
        app(SignIngredientLotFeature::class)($lot->fresh(), 'tipped', $user);

        $completed = app(CompleteBatchFeature::class)($batch->fresh(), $user);

        $this->assertSame(BatchRecord::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame($user->id, $completed->completed_by);

        $this->assertDatabaseHas('electronic_signatures', [
            'entity_name' => 'batch_records',
            'entity_id' => $batch->id,
            'signature_purpose' => 'batch_complete',
        ]);
        $this->assertSame(3, ElectronicSignature::count()); // weighed + tipped + batch_complete
        $this->assertDatabaseHas('audit_trails', [
            'entity_name' => 'batch_records',
            'action' => 'complete',
        ]);
    }

    public function test_signoff_requires_lot_number_and_quantity(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch();

        $lot = BatchIngredientLot::create([
            'batch_record_id' => $batch->id,
            'material_description' => 'Salt',
            'lot_number' => null,
            'actual_quantity' => null,
        ]);

        $this->expectException(BatchException::class);

        app(SignIngredientLotFeature::class)($lot, 'weighed', $user);
    }

    public function test_tipped_signoff_requires_prior_weighed_signoff_and_preserves_operator_identity(): void
    {
        $weighedBy = User::factory()->create(['name' => 'Weigh Operator']);
        $tippedBy = User::factory()->create(['name' => 'Tip Operator']);
        $batch = $this->makeBatch();

        $lot = app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Salt',
            'lot_number' => 'LOT-SALT',
            'actual_quantity' => 25,
            'uom' => 'kg',
        ], $weighedBy);

        try {
            app(SignIngredientLotFeature::class)($lot, 'tipped', $tippedBy);
            $this->fail('Expected tipped sign-off to be blocked before weighed sign-off.');
        } catch (BatchException $e) {
            $this->assertSame('Ingredient must be weighed before tipped sign-off.', $e->getMessage());
        }

        app(SignIngredientLotFeature::class)($lot->fresh(), 'weighed', $weighedBy);
        $signed = app(SignIngredientLotFeature::class)($lot->fresh(), 'tipped', $tippedBy)->fresh();

        $this->assertSame($weighedBy->id, $signed->weighed_by);
        $this->assertSame($tippedBy->id, $signed->tipped_by);
        $this->assertNotNull($signed->weighed_at);
        $this->assertNotNull($signed->tipped_at);
    }
}
