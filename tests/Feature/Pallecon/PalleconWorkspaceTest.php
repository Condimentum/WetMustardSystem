<?php

namespace Tests\Feature\Pallecon;

use App\Features\Pallecon\CreatePalleconWithFillFeature;
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

/**
 * The Pallecon Workspace page is now a per-pallecon detail view (?pallecon=id).
 * Creation lives on the MO Workspace - see WorkspacePalleconTest.
 */
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
            'batch_record_id' => $batch->id, 'material_code' => 'MAT1', 'material_description' => 'Material',
            'lot_number' => 'LOT-1', 'actual_quantity' => 10,
        ]);

        if ($signedOff) {
            foreach ([
                'ingredients_signoff.powders_weighed_by' => ['Powders Weighed By', 900],
                'ingredients_signoff.liquids_weighed_by' => ['Liquids Weighed By', 901],
                'ingredients_signoff.tipping_batch_by' => ['Tipping Batch By', 902],
            ] as $rowKey => [$label, $rowOrder]) {
                \App\Models\PaperworkRow::create([
                    'manufacturing_order_id' => $batch->manufacturing_order_id, 'batch_record_id' => $batch->id,
                    'batch_number' => (string) $batch->batch_number, 'batch_column_index' => 1,
                    'row_key' => $rowKey, 'row_label' => $label, 'row_order' => $rowOrder,
                    'value_text' => $user->name, 'status' => 'completed',
                ]);
            }
        }

        return $batch;
    }

    /** Create the pallecon + first fill the way the MO Workspace does. */
    private function openPalleconFor(BatchRecord $batch, string $serial, float $fillWeight = 400): Pallecon
    {
        return app(CreatePalleconWithFillFeature::class)(
            $batch->manufacturingOrder,
            $serial,
            $batch->load('manufacturingOrder', 'product'),
            $fillWeight,
            User::factory()->create(),
        )['pallecon'];
    }

    public function test_the_page_shows_the_pallecon_named_in_the_query(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6001, 'WM-PW-01');
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-1');

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6001])
            ->set('palleconId', $pallecon->id)
            ->assertOk()
            ->assertSee('PAL-PW-1')
            ->assertSee('WM-PW-01');
    }

    public function test_further_fills_go_into_the_selected_pallecon(): void
    {
        $this->actingAs(User::factory()->create());
        $batch1 = $this->makeBatch(6002, 'WM-PW-02A');
        $batch2 = $this->makeBatch(6002, 'WM-PW-02B');
        $pallecon = $this->openPalleconFor($batch1, 'PAL-PW-2', 400);

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6002])
            ->set('palleconId', $pallecon->id)
            ->set('fillBatchId', (string) $batch2->id)
            ->set('fillWeight', '300')
            ->call('addFill')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(700.0, $pallecon->fresh()->filledWeight(), 0.001);
        $this->assertSame(2, $pallecon->fresh()->fills()->count());
    }

    public function test_fill_weight_cannot_exceed_the_batch_remaining(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6003, 'WM-PW-CAP');       // planned 800
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-CAP', 500); // 300 left

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6003])
            ->set('palleconId', $pallecon->id)
            ->set('fillBatchId', (string) $batch->id)
            ->set('fillWeight', '350')
            ->call('addFill');

        $this->assertSame(1, $pallecon->fresh()->fills()->count());
        $this->assertStringContainsString('remaining on batch', (string) $component->get('flash'));

        $component->set('fillWeight', '300')->call('addFill')->assertHasNoErrors();
        $this->assertSame(2, $pallecon->fresh()->fills()->count());
    }

    public function test_seal_and_liner_details_are_saved_onto_the_pallecon(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6004, 'WM-PW-DET');
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-DET');

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6004])
            ->set('palleconId', $pallecon->id)
            ->set('containerForm.top_seal_number', 'TS-9')
            ->set('containerForm.bottom_seal_number', 'BS-9')
            ->set('containerForm.liner_number', 'LN-9')
            ->call('saveContainerDetails')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pallecons', [
            'id' => $pallecon->id, 'top_seal_number' => 'TS-9', 'bottom_seal_number' => 'BS-9', 'liner_number' => 'LN-9',
        ]);
    }

    public function test_pallecon_can_be_completed_with_a_final_weight(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6005, 'WM-PW-07');
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-7');

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6005])
            ->set('palleconId', $pallecon->id)
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '690')
            ->call('completePallecon')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pallecons', [
            'id' => $pallecon->id, 'status' => Pallecon::STATUS_SEALED, 'final_weight' => 690,
        ]);
    }

    public function test_a_pallecon_from_another_mo_is_not_shown(): void
    {
        $this->actingAs(User::factory()->create());
        $mine = $this->makeBatch(6006, 'WM-PW-MINE');
        $other = $this->makeBatch(6007, 'WM-PW-OTHER');
        $foreign = $this->openPalleconFor($other, 'PAL-FOREIGN');

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6006])
            ->set('palleconId', $foreign->id)
            ->assertOk()
            ->assertSee('No pallecon selected')
            ->assertDontSee('PAL-FOREIGN');
    }

    public function test_completed_list_shows_only_pallecons_entirely_from_this_mo(): void
    {
        $this->actingAs(User::factory()->create());
        $mineA = $this->makeBatch(6200, 'WM-PW-MINE-A');
        $mineB = $this->makeBatch(6200, 'WM-PW-MINE-B');
        $other = $this->makeBatch(6201, 'WM-PW-OTHER-2');

        $own = Pallecon::create(['serial_number' => 'PAL-OWN', 'status' => Pallecon::STATUS_SEALED, 'sealed_at' => now(), 'final_weight' => 700]);
        PalleconFill::create(['pallecon_id' => $own->id, 'batch_record_id' => $mineA->id, 'fill_weight' => 400, 'sequence' => 1]);
        PalleconFill::create(['pallecon_id' => $own->id, 'batch_record_id' => $mineB->id, 'fill_weight' => 300, 'sequence' => 2]);

        $shared = Pallecon::create(['serial_number' => 'PAL-SHARED', 'status' => Pallecon::STATUS_SEALED, 'sealed_at' => now(), 'final_weight' => 700]);
        PalleconFill::create(['pallecon_id' => $shared->id, 'batch_record_id' => $mineA->id, 'fill_weight' => 400, 'sequence' => 1]);
        PalleconFill::create(['pallecon_id' => $shared->id, 'batch_record_id' => $other->id, 'fill_weight' => 300, 'sequence' => 2]);

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6200])
            ->assertOk()
            ->assertSee('PAL-OWN')
            ->assertDontSee('PAL-SHARED');
    }
}
