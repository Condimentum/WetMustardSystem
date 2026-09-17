<?php

namespace Tests\Feature\Pallecon;

use App\Domains\WinMan\Jobs\CallManufacturingOrderFinishingJob;
use App\Domains\WinMan\Jobs\CheckWinManInventoryDuplicateJob;
use App\Domains\WinMan\Jobs\GetWinManProductPackSizeJob;
use App\Domains\WinMan\Jobs\ReadMoBookingContextJob;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\OpenPalleconFeature;
use App\Models\BatchIngredientLot;
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

/**
 * The Pallecon Workspace page is a per-pallecon detail view (?pallecon=id).
 * Empty pallecons are opened from the MO Workspace - see WorkspacePalleconTest.
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

    /**
     * @param  array{context?: mixed, duplicates?: array, packSize?: ?float, finishing?: mixed, finishingCalls?: int}  $overrides
     */
    private function mockWinMan(array $overrides = []): void
    {
        $context = Mockery::mock(ReadMoBookingContextJob::class);
        $context->shouldReceive('__invoke')->andReturn($overrides['context'] ?? [
            'last_modified_date' => '2026-01-01 00:00:00', 'quantity_outstanding' => 5200.0, 'quantity' => 5200.0, 'location' => 5,
        ]);
        $this->instance(ReadMoBookingContextJob::class, $context);

        $dup = Mockery::mock(CheckWinManInventoryDuplicateJob::class);
        $dup->shouldReceive('__invoke')->andReturn($overrides['duplicates'] ?? []);
        $this->instance(CheckWinManInventoryDuplicateJob::class, $dup);

        $pack = Mockery::mock(GetWinManProductPackSizeJob::class);
        $pack->shouldReceive('__invoke')->andReturn($overrides['packSize'] ?? 1000.0);
        $this->instance(GetWinManProductPackSizeJob::class, $pack);

        $finishing = Mockery::mock(CallManufacturingOrderFinishingJob::class);
        $expectation = $finishing->shouldReceive('__invoke');
        if (array_key_exists('finishingCalls', $overrides)) {
            $expectation->times($overrides['finishingCalls']);
        }
        $expectation->andReturn($overrides['finishing'] ?? ['completed_inventory' => 987654, 'last_modified_date' => '2026-01-02 00:00:00']);
        $this->instance(CallManufacturingOrderFinishingJob::class, $finishing);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Open an empty MO pallecon (as the MO Workspace does) and optionally add one fill. */
    private function openPalleconFor(BatchRecord $batch, ?string $serial = null, ?float $fillWeight = 400): Pallecon
    {
        $user = User::factory()->create();
        $pallecon = app(OpenPalleconFeature::class)([
            'manufacturing_order_id' => $batch->manufacturing_order_id,
            'mo_number' => $batch->manufacturingOrder->mo_number,
            'serial_number' => $serial,
            'target_weight_kg' => 1000,
            'production_date' => now()->toDateString(),
        ], $user);

        if ($fillWeight !== null) {
            app(AttachBatchFillFeature::class)($pallecon, $batch->load('manufacturingOrder', 'product'), ['fill_weight' => $fillWeight], $user);
        }

        return $pallecon->fresh();
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
        $batch2 = $this->makeBatch(6002, 'WM-PW-02B', true, 300);
        $pallecon = $this->openPalleconFor($batch1, 'PAL-PW-2', 400);

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6002])
            ->set('palleconId', $pallecon->id)
            ->call('issueBatchToPallecon', $batch2->id)
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(700.0, $pallecon->fresh()->filledWeight(), 0.001);
        $this->assertSame(2, $pallecon->fresh()->fills()->count());
    }

    public function test_issuing_a_batch_does_not_call_winman(): void
    {
        $this->actingAs(User::factory()->create());
        config(['winman.booking.enabled' => true]);
        $batch1 = $this->makeBatch(6009, 'WM-PW-NOWM-A');
        $batch2 = $this->makeBatch(6009, 'WM-PW-NOWM-B', true, 300);
        $pallecon = $this->openPalleconFor($batch1, 'PAL-PW-NOWM', 400);

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6009])
            ->set('palleconId', $pallecon->id)
            ->call('issueBatchToPallecon', $batch2->id)
            ->assertHasNoErrors()
            ->assertSet('winman_booking_results', []);

        $this->assertDatabaseCount('winman_booking_logs', 0);
    }

    public function test_issuing_a_batch_auto_caps_to_the_pallecons_remaining_room(): void
    {
        $this->actingAs(User::factory()->create());
        $batch1 = $this->makeBatch(6003, 'WM-PW-CAP-A', true, 785);
        $batch2 = $this->makeBatch(6003, 'WM-PW-CAP-B', true, 785);
        $pallecon = $this->openPalleconFor($batch1, 'PAL-PW-CAP', null);
        $pallecon->update(['target_weight_kg' => 1100]);

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6003])
            ->set('palleconId', $pallecon->id)
            ->call('issueBatchToPallecon', $batch1->id)
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(785.0, $pallecon->fresh()->filledWeight(), 0.001);

        $component->call('issueBatchToPallecon', $batch2->id)->assertHasNoErrors();

        // Pallecon filled to exactly its 1100kg target - the 2nd batch's fill was
        // capped to the 315kg of room left, not the full 785kg requested.
        $this->assertEqualsWithDelta(1100.0, $pallecon->fresh()->filledWeight(), 0.001);
        $this->assertEqualsWithDelta(315.0, (float) $batch2->palleconFills()->sum('fill_weight'), 0.001);
        $this->assertStringContainsString('full', (string) $component->get('flash'));
        $this->assertStringContainsString('470', (string) $component->get('flash'));
    }

    public function test_pallecon_number_auto_saves_without_an_explicit_save_step(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6004, 'WM-PW-DET');
        $pallecon = $this->openPalleconFor($batch, null);   // no number yet

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6004])
            ->set('palleconId', $pallecon->id)
            ->set('containerForm.serial_number', 'PAL-DET-1')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pallecons', ['id' => $pallecon->id, 'serial_number' => 'PAL-DET-1']);
    }

    public function test_sealing_stamps_the_winman_reference_and_seal_liner_details(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6005, 'WM-PW-07');
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-7');

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6005])
            ->set('palleconId', $pallecon->id)
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '690')
            ->set('sealForm.top_seal_number', 'TS-9')
            ->set('sealForm.bottom_seal_number', 'BS-9')
            ->set('sealForm.liner_number', 'LN-9')
            ->call('completePallecon')
            ->assertHasNoErrors();

        $sealed = $pallecon->fresh();
        $this->assertSame(Pallecon::STATUS_SEALED, $sealed->status);
        $this->assertSame('690.000', (string) $sealed->final_weight);
        $this->assertSame('TS-9', $sealed->top_seal_number);
        $this->assertSame('BS-9', $sealed->bottom_seal_number);
        $this->assertSame('LN-9', $sealed->liner_number);
        // "{MO WinMan id} {pallecon number} {yjjj}00M96"
        $this->assertMatchesRegularExpression('/^MOPW6005 PALPW7 \d{4}00M96$/', (string) $sealed->winman_reference);
    }

    public function test_completing_a_pallecon_books_once_for_the_whole_container(): void
    {
        $this->actingAs(User::factory()->create());
        config(['winman.booking.enabled' => true]);
        $this->mockWinMan(['finishingCalls' => 1]);
        $batch1 = $this->makeBatch(6010, 'WM-PW-BOOK-A');
        $batch2 = $this->makeBatch(6010, 'WM-PW-BOOK-B', true, 300);
        $pallecon = $this->openPalleconFor($batch1, 'PAL-PW-BOOK', 400);

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6010])
            ->set('palleconId', $pallecon->id)
            ->call('issueBatchToPallecon', $batch2->id)
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '700');

        $component->call('completePallecon')->assertHasNoErrors();

        $sealed = $pallecon->fresh();
        $this->assertSame(Pallecon::STATUS_SEALED, $sealed->status);
        $this->assertCount(1, $component->get('winman_booking_results'));
        // One physical pallecon books as a single WinMan transaction, however
        // many batches contributed fills to it - not one booking per fill.
        $this->assertDatabaseCount('winman_booking_logs', 1);
        $this->assertDatabaseHas('winman_booking_logs', [
            'pallecon_id' => $sealed->id,
            'batch_record_id' => $batch1->id, // the first (primary) batch added
            'quantity_booked_kg' => '700.000', // the sealed final weight, not the sum of fills
            'lot_number' => $sealed->winman_reference,
            'booking_status' => 'success',
        ]);
    }

    public function test_completing_a_pallecon_redirects_away_from_the_now_sealed_container(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6013, 'WM-PW-DONE');
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-DONE', 400);

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6013])
            ->set('palleconId', $pallecon->id)
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '400')
            ->call('completePallecon')
            ->assertRedirect(route('manufacturing-orders.pallecons', ['winmanMo' => 6013]));

        // Landing back on the (unscoped) Pallecon Workspace, the now-sealed
        // container is no longer selected - so it can't be re-filled, renamed
        // or re-sealed - and the confirmation carries over via session.
        $page = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6013])
            ->assertSet('palleconId', null)
            ->assertSee('completed at 400');

        $this->assertNull($page->get('activeContainer')?->id);
    }

    public function test_completing_a_pallecon_succeeds_locally_even_if_a_booking_fails(): void
    {
        $this->actingAs(User::factory()->create());
        config(['winman.booking.enabled' => true]);
        $this->mockWinMan([
            'context' => ['last_modified_date' => '2026-01-01 00:00:00', 'quantity_outstanding' => 0.0, 'quantity' => 5200.0, 'location' => 5],
            'finishingCalls' => 0,
        ]);
        $batch = $this->makeBatch(6011, 'WM-PW-BOOKFAIL');
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-BOOKFAIL', 400);

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6011])
            ->set('palleconId', $pallecon->id)
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '400')
            ->call('completePallecon');

        $component->assertHasNoErrors()->assertSet('flashError', false);
        $this->assertSame(Pallecon::STATUS_SEALED, $pallecon->fresh()->status);

        $results = $component->get('winman_booking_results');
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['error']);
        $this->assertDatabaseHas('winman_booking_logs', ['booking_status' => 'rejected']);
    }

    public function test_retry_winman_booking_rebooks_an_unbooked_pallecon(): void
    {
        $this->actingAs(User::factory()->create());
        config(['winman.booking.enabled' => true]);
        $this->mockWinMan([
            'context' => ['last_modified_date' => '2026-01-01 00:00:00', 'quantity_outstanding' => 0.0, 'quantity' => 5200.0, 'location' => 5],
            'finishingCalls' => 0,
        ]);
        $batch = $this->makeBatch(6012, 'WM-PW-RETRY');
        $pallecon = $this->openPalleconFor($batch, 'PAL-PW-RETRY', 400);

        $component = Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6012])
            ->set('palleconId', $pallecon->id)
            ->call('startSeal', $pallecon->id)
            ->set('sealForm.final_weight', '400')
            ->call('completePallecon');

        $this->assertDatabaseHas('winman_booking_logs', ['booking_status' => 'rejected']);

        // Now WinMan would accept the booking.
        $this->mockWinMan(['finishingCalls' => 1]);

        $component->call('retryWinManBooking', $pallecon->id)->assertSet('flashError', false);

        $this->assertDatabaseHas('winman_booking_logs', ['booking_status' => 'success']);
        $this->assertTrue($pallecon->fresh()->isWinManBooked());
    }

    public function test_a_pallecon_from_another_mo_is_not_shown(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeBatch(6006, 'WM-PW-MINE');
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

    public function test_a_sealed_pallecon_scoped_by_query_shows_a_read_only_summary(): void
    {
        $this->actingAs(User::factory()->create());
        $batch = $this->makeBatch(6210, 'WM-PW-SEALED');

        $sealed = Pallecon::create([
            'serial_number' => 'PAL-SEALED-1', 'manufacturing_order_id' => $batch->manufacturing_order_id,
            'status' => Pallecon::STATUS_SEALED, 'sealed_at' => now(), 'final_weight' => 690,
            'top_seal_number' => 'TS-1', 'bottom_seal_number' => 'BS-1', 'liner_number' => 'LN-1',
            'winman_reference' => 'REF-123',
        ]);
        PalleconFill::create(['pallecon_id' => $sealed->id, 'batch_record_id' => $batch->id, 'fill_weight' => 690, 'sequence' => 1]);

        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 6210])
            ->set('palleconId', $sealed->id)
            ->assertOk()
            ->assertSee('PAL-SEALED-1')
            ->assertSee('Batches used')
            ->assertSee('Label prints')
            ->assertSee('WM-PW-SEALED')
            ->assertDontSee('Issue to Pallecon')
            ->assertDontSee('Complete pallecon');
    }
}
