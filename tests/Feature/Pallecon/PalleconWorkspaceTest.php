<?php

namespace Tests\Feature\Pallecon;

use App\Models\BatchIngredientLot;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconFill;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PalleconWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeBatch(int $winmanMo, string $batchNumber, bool $signedOff = true, float $planned = 800): BatchRecord
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
            'production_date' => now()->toDateString(), 'planned_quantity' => $planned, 'status' => BatchRecord::STATUS_IN_PROGRESS,
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

    public function test_creating_a_pallecon_opens_it_and_records_the_first_fill(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6001, 'WM-PW-01');

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6001])
            ->set('fillBatchId', (string) $batch->id)
            ->set('palleconNumber', 'PAL-PW-1')
            ->set('fillWeight', '400')
            ->call('createPallecon')
            ->assertHasNoErrors();

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-1')->first();
        $this->assertNotNull($pallecon);
        $this->assertSame(Pallecon::STATUS_FILLING, $pallecon->status);
        $this->assertDatabaseHas('pallecon_fills', [
            'pallecon_id' => $pallecon->id,
            'batch_record_id' => $batch->id,
            'fill_weight' => 400,
        ]);
    }

    public function test_only_one_open_pallecon_per_mo_at_a_time(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6002, 'WM-PW-02');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6002])
            ->set('fillBatchId', (string) $batch->id)
            ->set('palleconNumber', 'PAL-PW-2A')
            ->set('fillWeight', '300')
            ->call('createPallecon')
            ->assertHasNoErrors();

        // Second create is rejected while one is still open for this MO.
        $component
            ->set('palleconNumber', 'PAL-PW-2B')
            ->set('fillWeight', '100')
            ->call('createPallecon');

        $this->assertNull(Pallecon::where('serial_number', 'PAL-PW-2B')->first());
        $this->assertStringContainsString('already open for this MO', (string) $component->get('flash'));
    }

    public function test_further_fills_go_into_the_open_pallecon(): void
    {
        $this->actingAs(User::factory()->create());
        $batch1 = $this->makeBatch(6003, 'WM-PW-03A');
        $batch2 = $this->makeBatch(6003, 'WM-PW-03B');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6003])
            ->set('fillBatchId', (string) $batch1->id)
            ->set('palleconNumber', 'PAL-PW-3')
            ->set('fillWeight', '400')
            ->call('createPallecon')
            ->set('fillBatchId', (string) $batch2->id)
            ->set('fillWeight', '300')
            ->call('addFill')
            ->assertHasNoErrors();

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-3')->first();
        $this->assertEqualsWithDelta(700.0, $pallecon->fresh()->filledWeight(), 0.001);
        $this->assertSame(2, $pallecon->fresh()->fills()->count());
    }

    public function test_batch_without_signoff_cannot_create_a_pallecon(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6004, 'WM-PW-04', signedOff: false);

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6004])
            ->set('fillBatchId', (string) $batch->id)
            ->set('palleconNumber', 'PAL-PW-4')
            ->set('fillWeight', '100')
            ->call('createPallecon');

        $this->assertDatabaseCount('pallecons', 0);
        $this->assertStringContainsString('Ingredients Sign Off', (string) $component->get('flash'));
    }

    public function test_a_batch_from_another_mo_cannot_be_used(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeBatch(6005, 'WM-PW-05');
        $otherBatch = $this->makeBatch(6006, 'WM-PW-06');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6005])
            ->set('fillBatchId', (string) $otherBatch->id)
            ->set('palleconNumber', 'PAL-PW-5')
            ->set('fillWeight', '100')
            ->call('createPallecon');

        $this->assertDatabaseCount('pallecons', 0);
        $this->assertStringContainsString('Select a batch from this manufacturing order', (string) $component->get('flash'));
    }

    public function test_fill_weight_cannot_exceed_the_batch_planned_quantity(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6100, 'WM-PW-CAP');   // planned 800

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6100])
            ->set('fillBatchId', (string) $batch->id)
            ->set('palleconNumber', 'PAL-PW-CAP')
            ->set('fillWeight', '900')
            ->call('createPallecon');

        $this->assertDatabaseCount('pallecons', 0);
        $this->assertStringContainsString('remaining on batch', (string) $component->get('flash'));

        // A partial 500 kg fill creates the pallecon and leaves 300 kg remaining.
        $component->set('fillWeight', '500')->call('createPallecon')->assertHasNoErrors();
        $this->assertDatabaseHas('pallecon_fills', ['batch_record_id' => $batch->id, 'fill_weight' => 500]);

        $remaining = collect($component->instance()->moBatches)->firstWhere('id', $batch->id)['remaining_kg'];
        $this->assertEqualsWithDelta(300.0, $remaining, 0.001);

        // A further fill above the now-300 kg remaining is blocked.
        $component->set('fillWeight', '350')->call('addFill');
        $this->assertDatabaseCount('pallecon_fills', 1);
    }

    public function test_seal_and_liner_details_are_saved_onto_the_open_pallecon(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6110, 'WM-PW-DET');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6110])
            ->set('fillBatchId', (string) $batch->id)
            ->set('palleconNumber', 'PAL-PW-DET')
            ->set('fillWeight', '400')
            ->call('createPallecon')
            ->set('containerForm.top_seal_number', 'TS-9')
            ->set('containerForm.bottom_seal_number', 'BS-9')
            ->set('containerForm.liner_number', 'LN-9')
            ->call('saveContainerDetails')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pallecons', [
            'serial_number' => 'PAL-PW-DET',
            'top_seal_number' => 'TS-9',
            'bottom_seal_number' => 'BS-9',
            'liner_number' => 'LN-9',
        ]);
    }

    public function test_open_pallecon_can_be_completed_with_a_final_weight(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6006, 'WM-PW-07');

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6006])
            ->set('fillBatchId', (string) $batch->id)
            ->set('palleconNumber', 'PAL-PW-7')
            ->set('fillWeight', '400')
            ->call('createPallecon');

        $pallecon = Pallecon::where('serial_number', 'PAL-PW-7')->first();

        $component
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

    public function test_completed_list_shows_only_pallecons_entirely_from_this_mo(): void
    {
        $this->actingAs(User::factory()->create());
        $mineA = $this->makeBatch(6200, 'WM-PW-MINE-A');
        $mineB = $this->makeBatch(6200, 'WM-PW-MINE-B');
        $other = $this->makeBatch(6201, 'WM-PW-OTHER');

        // Sealed pallecon filled only from this MO -> listed.
        $ownContainer = Pallecon::create(['serial_number' => 'PAL-OWN', 'status' => Pallecon::STATUS_SEALED, 'sealed_at' => now(), 'final_weight' => 700]);
        PalleconFill::create(['pallecon_id' => $ownContainer->id, 'batch_record_id' => $mineA->id, 'fill_weight' => 400, 'sequence' => 1]);
        PalleconFill::create(['pallecon_id' => $ownContainer->id, 'batch_record_id' => $mineB->id, 'fill_weight' => 300, 'sequence' => 2]);

        // Sealed pallecon shared with another MO -> hidden here.
        $sharedContainer = Pallecon::create(['serial_number' => 'PAL-SHARED', 'status' => Pallecon::STATUS_SEALED, 'sealed_at' => now(), 'final_weight' => 700]);
        PalleconFill::create(['pallecon_id' => $sharedContainer->id, 'batch_record_id' => $mineA->id, 'fill_weight' => 400, 'sequence' => 1]);
        PalleconFill::create(['pallecon_id' => $sharedContainer->id, 'batch_record_id' => $other->id, 'fill_weight' => 300, 'sequence' => 2]);

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6200])
            ->assertOk()
            ->assertSee('PAL-OWN')
            ->assertDontSee('PAL-SHARED');
    }
}
