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
use App\Models\LabelPrintLog;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconFill;
use App\Models\PaperworkRow;
use App\Models\Product;
use App\Models\User;
use App\Models\WinManBookingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
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

    private function confirmIngredientsSignoff(BatchRecord $batch, User $operator): void
    {
        foreach ([
            'ingredients_signoff.powders_weighed_by' => ['Powders Weighed By', 900],
            'ingredients_signoff.liquids_weighed_by' => ['Liquids Weighed By', 901],
            'ingredients_signoff.tipping_batch_by' => ['Tipping Batch By', 902],
        ] as $rowKey => [$label, $order]) {
            PaperworkRow::create([
                'manufacturing_order_id' => $batch->manufacturing_order_id,
                'batch_record_id' => $batch->id,
                'batch_number' => (string) $batch->batch_number,
                'batch_column_index' => 1,
                'row_key' => $rowKey,
                'row_label' => $label,
                'row_order' => $order,
                'value_text' => $operator->name,
                'status' => 'completed',
            ]);
        }
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

        // Add a lot; still missing the batch-level sign-off confirmations.
        app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Spirit Vinegar 14%',
            'lot_number' => 'LOT-001',
            'actual_quantity' => 655.2,
            'uom' => 'kg',
        ], $user);

        $issues = app(ValidateBatchCompletionJob::class)($batch->fresh());
        $this->assertContains("Ingredients sign-off confirmation 'Powders Weighed' is missing.", $issues);
        $this->assertContains("Ingredients sign-off confirmation 'Tipping Batch' is missing.", $issues);

        $this->confirmIngredientsSignoff($batch, $user);

        $this->assertSame([], app(ValidateBatchCompletionJob::class)($batch->fresh()));
    }

    public function test_completing_a_valid_batch_records_signatures_and_status(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch();

        app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Water',
            'lot_number' => 'LOT-W',
            'actual_quantity' => 100,
            'uom' => 'kg',
        ], $user);
        $this->confirmIngredientsSignoff($batch, $user);

        $completed = app(CompleteBatchFeature::class)($batch->fresh(), $user);

        $this->assertSame(BatchRecord::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame($user->id, $completed->completed_by);

        $this->assertDatabaseHas('electronic_signatures', [
            'entity_name' => 'batch_records',
            'entity_id' => $batch->id,
            'signature_purpose' => 'batch_complete',
        ]);
        $this->assertSame(1, ElectronicSignature::count()); // batch_complete
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

    public function test_ingredients_signoff_can_be_reset_and_resubmitted_on_batch_screen(): void
    {
        $user = User::factory()->create(['name' => 'Reset Tester']);
        $batch = $this->makeBatch();
        // Pallecon packing mode so the sign-off confirmations apply.
        $batch->manufacturingOrder->update(['winman_unit_of_measure_description' => 'PALLECON']);

        app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Water',
            'lot_number' => 'LOT-W',
            'actual_quantity' => 100,
            'uom' => 'kg',
        ], $user);
        $this->confirmIngredientsSignoff($batch, $user);
        $this->assertSame([], app(ValidateBatchCompletionJob::class)($batch->fresh()));

        $this->actingAs($user);
        $component = Volt::test('pages.batches.show', ['batch' => $batch->fresh()]);

        $component->call('resetIngredientSignoff');

        // A single reset request must already render the empty dropdowns back.
        $component->assertSee('Select operator');
        $component->assertDontSee('Reset sign-off');

        $blankRows = PaperworkRow::query()
            ->where('batch_record_id', $batch->id)
            ->where('row_key', 'like', 'ingredients_signoff.%')
            ->get();
        $this->assertCount(3, $blankRows);
        foreach ($blankRows as $row) {
            $this->assertNull($row->value_text);
            $this->assertSame('pending', $row->status);
        }

        $issues = app(ValidateBatchCompletionJob::class)($batch->fresh());
        $this->assertContains("Ingredients sign-off confirmation 'Powders Weighed' is missing.", $issues);

        $this->assertDatabaseHas('audit_trails', [
            'entity_name' => 'batch_records',
            'entity_id' => $batch->id,
            'action' => 'ingredients_signoff_reset',
        ]);

        $component
            ->set('powdersWeighedOperatorId', (string) $user->id)
            ->set('liquidsWeighedOperatorId', (string) $user->id)
            ->set('tippingBatchOperatorId', (string) $user->id)
            ->call('applyBulkIngredientSignoff');

        $this->assertSame([], app(ValidateBatchCompletionJob::class)($batch->fresh()));
        $this->assertDatabaseHas('paperwork_rows', [
            'batch_record_id' => $batch->id,
            'row_key' => 'ingredients_signoff.powders_weighed_by',
            'value_text' => 'Reset Tester',
            'status' => 'completed',
        ]);

        // A single submit request must flip the UI to the signed-off state -
        // no "submit twice" (stale computed-property cache) regression.
        $component->assertSee('Reset sign-off');
        $component->assertSee('Submitted');
        $component->assertDontSee('Submit Ingredients Sign Off');
    }

    public function test_completed_batch_still_shows_who_signed_ingredients_but_hides_editable_controls(): void
    {
        $user = User::factory()->create(['name' => 'Read Only Tester']);
        $batch = $this->makeBatch();
        $batch->manufacturingOrder->update(['winman_unit_of_measure_description' => 'PALLECON']);

        app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Water',
            'lot_number' => 'LOT-W',
            'actual_quantity' => 100,
            'uom' => 'kg',
        ], $user);
        $this->confirmIngredientsSignoff($batch, $user);
        app(CompleteBatchFeature::class)($batch->fresh(), $user);

        $this->actingAs($user);
        $component = Volt::test('pages.batches.show', ['batch' => $batch->fresh()]);

        // Read-only batches must still show who signed off the ingredients...
        $component->assertSee('Submitted');
        $component->assertSee('Read Only Tester');

        // ...but no controls to resubmit or reset it.
        $component->assertDontSee('Select operator');
        $component->assertDontSee('Submit Ingredients Sign Off');
        $component->assertDontSee('Reset sign-off');
    }

    public function test_view_on_a_completed_batch_expands_the_row_instead_of_opening_the_allocate_modal(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch();

        app(AddIngredientLotFeature::class)($batch, [
            'material_description' => 'Water',
            'lot_number' => 'LOT-W',
            'actual_quantity' => 100,
            'uom' => 'kg',
        ], $user);
        $this->confirmIngredientsSignoff($batch, $user);
        app(CompleteBatchFeature::class)($batch->fresh(), $user);

        $this->actingAs($user);
        Volt::test('pages.batches.show', ['batch' => $batch->fresh()])
            ->call('openAllocateModal', 1, 'MAT1', 'Material One', '10')
            ->assertSet('activeBomComponentSnapshotId', 1)
            ->assertSet('showAllocateModal', false);
    }

    public function test_batch_screen_shows_label_and_winman_history_view_only(): void
    {
        $user = User::factory()->create(['name' => 'History Viewer']);
        $batch = $this->makeBatch();

        $pallecon = Pallecon::create([
            'manufacturing_order_id' => $batch->manufacturing_order_id, 'serial_number' => 'PAL-HIST-1',
            'status' => Pallecon::STATUS_SEALED, 'target_weight_kg' => 400, 'final_weight' => 400,
            'production_date' => now()->toDateString(), 'sealed_at' => now(),
        ]);
        $fill = PalleconFill::create([
            'pallecon_id' => $pallecon->id, 'batch_record_id' => $batch->id, 'fill_weight' => 400, 'sequence' => 1,
        ]);

        LabelPrintLog::create([
            'pallecon_id' => $pallecon->id, 'batch_record_id' => $batch->id, 'printed_by' => $user->id,
            'label_type' => 'pallecon', 'serial_number' => 'PAL-HIST-1', 'fill_weight' => 400,
            'production_date' => now()->toDateString(), 'status' => LabelPrintLog::STATUS_SUCCESS,
            'label_data' => ['BatchNumber' => $batch->batch_number, 'ProductId' => '50010007'],
            'printed_at' => now(),
        ]);
        LabelPrintLog::create([
            'pallecon_id' => $pallecon->id, 'batch_record_id' => $batch->id, 'printed_by' => $user->id,
            'label_type' => 'pallecon', 'serial_number' => 'PAL-HIST-1', 'fill_weight' => 400,
            'production_date' => now()->toDateString(), 'status' => LabelPrintLog::STATUS_FAILED,
            'error_message' => 'BarTender integration is disabled.', 'printed_at' => now(),
        ]);

        WinManBookingLog::create([
            'batch_record_id' => $batch->id, 'pallecon_id' => $pallecon->id, 'winman_inventory_id' => 555111,
            'winman_manufacturing_order' => $batch->manufacturingOrder->winman_manufacturing_order,
            'lot_number' => 'MO1 PAL-HIST-1 25258 00M96', 'quantity_booked_kg' => 400, 'quantity_booked_traded_units' => 400,
            'booking_user' => 'History Viewer', 'booking_date' => now(), 'booking_status' => WinManBookingLog::STATUS_SUCCESS,
        ]);

        $this->actingAs($user);
        $component = Volt::test('pages.batches.show', ['batch' => $batch->fresh()]);

        $component->assertSee('WinMan History');
        $component->assertSee('PAL-HIST-1');
        $component->assertSee('Printed');
        $component->assertSee('Failed');
        $component->assertSeeText('BarTender integration is disabled.');
        $component->assertSee('555111');
        $component->assertSee('MO1 PAL-HIST-1 25258 00M96');
        $component->assertSee('Success');
    }
}
