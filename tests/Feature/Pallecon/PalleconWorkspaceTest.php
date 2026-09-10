<?php

namespace Tests\Feature\Pallecon;

use App\Models\BatchIngredientLot;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PalleconWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeBatch(int $winmanMo, string $batchNumber, bool $signedOff = true): BatchRecord
    {
        $this->seq++;
        $product = Product::create(['recipe_code' => 'RPW'.$this->seq, 'product_name' => 'Test', 'active_flag' => true]);
        $order = ManufacturingOrder::firstOrCreate(
            ['winman_manufacturing_order' => $winmanMo],
            [
                'mo_number' => 'MOPW'.$winmanMo, 'winman_manufacturing_order_id' => 'MOPW'.$winmanMo,
                'recipe_code' => 'RPW'.$this->seq, 'product_id' => $product->id, 'planned_quantity' => 800,
                'quantity_outstanding' => 800, 'winman_system_type' => 'F', 'status' => 'selected',
            ],
        );

        $batch = BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => $batchNumber,
            'production_date' => now()->toDateString(), 'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);

        $user = User::factory()->create();
        BatchIngredientLot::create([
            'batch_record_id' => $batch->id,
            'material_code' => 'MAT1',
            'material_description' => 'Material',
            'lot_number' => 'LOT-1',
            'actual_quantity' => 10,
        ]);

        if ($signedOff) {
            // Sign-off is a batch-level confirmation recorded as paperwork rows.
            foreach ([
                'ingredients_signoff.powders_weighed_by' => ['Powders Weighed By', 900],
                'ingredients_signoff.liquids_weighed_by' => ['Liquids Weighed By', 901],
                'ingredients_signoff.tipping_batch_by' => ['Tipping Batch By', 902],
            ] as $rowKey => [$label, $order]) {
                \App\Models\PaperworkRow::create([
                    'manufacturing_order_id' => $batch->manufacturing_order_id,
                    'batch_record_id' => $batch->id,
                    'batch_number' => (string) $batch->batch_number,
                    'batch_column_index' => 1,
                    'row_key' => $rowKey,
                    'row_label' => $label,
                    'row_order' => $order,
                    'value_text' => $user->name,
                    'status' => 'completed',
                ]);
            }
        }

        return $batch;
    }

    public function test_new_pallecon_can_be_opened_and_filled_with_batch_and_quantity(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6001, 'WM-PW-01');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6001])
            ->set('showNewForm', true)
            ->set('newForm.ticket_number', 'PAL-PW-1')
            ->call('openPallecon')
            ->assertHasNoErrors();

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-1')->first();
        $this->assertNotNull($pallecon);
        $this->assertSame(Pallecon::STATUS_OPEN, $pallecon->status);

        $component
            ->set('fillBatchId', (string) $batch->id)
            ->set('fillPalleconId', (string) $pallecon->id)
            ->set('fillWeight', '400')
            ->call('addFill')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pallecon_fills', [
            'pallecon_id' => $pallecon->id,
            'batch_record_id' => $batch->id,
            'fill_weight' => 400,
        ]);
        $this->assertSame(Pallecon::STATUS_FILLING, $pallecon->fresh()->status);
    }

    public function test_two_batches_can_fill_the_same_pallecon(): void
    {
        $this->actingAs(User::factory()->create());
        $batch1 = $this->makeBatch(6002, 'WM-PW-02');
        $batch2 = $this->makeBatch(6002, 'WM-PW-03');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6002])
            ->set('showNewForm', true)
            ->set('newForm.ticket_number', 'PAL-PW-2')
            ->call('openPallecon');

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-2')->first();

        $component
            ->set('fillBatchId', (string) $batch1->id)
            ->set('fillPalleconId', (string) $pallecon->id)
            ->set('fillWeight', '400')
            ->call('addFill')
            ->set('fillBatchId', (string) $batch2->id)
            ->set('fillPalleconId', (string) $pallecon->id)
            ->set('fillWeight', '300')
            ->call('addFill')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(700.0, $pallecon->fresh()->filledWeight(), 0.001);
        $this->assertSame(2, $pallecon->fresh()->fills()->count());
    }

    public function test_batch_without_signoff_cannot_fill(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6003, 'WM-PW-04', signedOff: false);

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6003])
            ->set('showNewForm', true)
            ->set('newForm.ticket_number', 'PAL-PW-3')
            ->call('openPallecon');

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-3')->first();

        $component
            ->set('fillBatchId', (string) $batch->id)
            ->set('fillPalleconId', (string) $pallecon->id)
            ->set('fillWeight', '100')
            ->call('addFill');

        $this->assertDatabaseCount('pallecon_fills', 0);
        $this->assertStringContainsString('Ingredients Sign Off', (string) $component->get('flash'));
    }

    public function test_batch_from_another_mo_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeBatch(6004, 'WM-PW-05');
        $otherBatch = $this->makeBatch(6005, 'WM-PW-06');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6004])
            ->set('showNewForm', true)
            ->set('newForm.ticket_number', 'PAL-PW-4')
            ->call('openPallecon');

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-4')->first();

        $component
            ->set('fillBatchId', (string) $otherBatch->id)
            ->set('fillPalleconId', (string) $pallecon->id)
            ->set('fillWeight', '100')
            ->call('addFill');

        $this->assertDatabaseCount('pallecon_fills', 0);
        $this->assertStringContainsString('does not belong', (string) $component->get('flash'));
    }

    public function test_filled_pallecon_can_be_completed_with_final_weight(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6006, 'WM-PW-07');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6006])
            ->set('showNewForm', true)
            ->set('newForm.ticket_number', 'PAL-PW-5')
            ->call('openPallecon');

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-5')->first();

        $component
            ->set('fillBatchId', (string) $batch->id)
            ->set('fillPalleconId', (string) $pallecon->id)
            ->set('fillWeight', '400')
            ->call('addFill')
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '690')
            ->call('completePallecon')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pallecons', [
            'id' => $pallecon->id,
            'status' => Pallecon::STATUS_SEALED,
            'final_weight' => 690,
        ]);
    }

    public function test_pallecon_without_fills_cannot_be_completed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeBatch(6007, 'WM-PW-08');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6007])
            ->set('showNewForm', true)
            ->set('newForm.ticket_number', 'PAL-PW-6')
            ->call('openPallecon');

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-6')->first();

        $component
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '100')
            ->call('completePallecon');

        $this->assertSame(Pallecon::STATUS_OPEN, $pallecon->fresh()->status);
        $this->assertStringContainsString('no batch fills', (string) $component->get('flash'));
    }
}
