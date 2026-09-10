<?php

namespace Tests\Feature\Pallecon;

use App\Domains\Pallecon\Exceptions\PalleconException;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\OpenPalleconFeature;
use App\Features\Pallecon\SealPalleconFeature;
use App\Features\Traceability\BackwardTraceFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PalleconFillingTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeBatch(string $batchNumber): BatchRecord
    {
        $this->seq++;
        $product = Product::create(['recipe_code' => 'R'.$this->seq, 'product_name' => 'Test', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MO'.$this->seq,
            'winman_manufacturing_order' => 1000 + $this->seq,
            'winman_manufacturing_order_id' => 'MO'.$this->seq,
            'recipe_code' => 'R'.$this->seq,
            'product_id' => $product->id,
            'planned_quantity' => 500,
            'quantity_outstanding' => 500,
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);

        return BatchRecord::create([
            'manufacturing_order_id' => $order->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'production_date' => now()->toDateString(),
            'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_one_pallecon_can_be_filled_from_two_batches(): void
    {
        $user = User::factory()->create();
        $batch1 = $this->makeBatch('WM260908-01');
        $batch2 = $this->makeBatch('WM260908-02');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-A'], $user);

        app(AttachBatchFillFeature::class)($pallecon, $batch1, ['fill_weight' => 400], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch2, ['fill_weight' => 300], $user);

        $pallecon->refresh();
        $this->assertSame(Pallecon::STATUS_FILLING, $pallecon->status);
        $this->assertEqualsWithDelta(700.0, $pallecon->filledWeight(), 0.001);
        $this->assertCount(2, $pallecon->fills);

        $this->assertDatabaseHas('pallecon_fills', ['pallecon_id' => $pallecon->id, 'batch_record_id' => $batch1->id, 'sequence' => 1]);
        $this->assertDatabaseHas('pallecon_fills', ['pallecon_id' => $pallecon->id, 'batch_record_id' => $batch2->id, 'sequence' => 2]);
        $this->assertDatabaseHas('electronic_signatures', ['entity_name' => 'pallecon_fills', 'signature_purpose' => 'pallecon_fill']);
    }

    public function test_one_batch_can_be_split_across_two_pallecons(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM260908-03');

        $palleconA = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-A'], $user);
        $palleconB = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-B'], $user);

        app(AttachBatchFillFeature::class)($palleconA, $batch, ['fill_weight' => 200], $user);
        app(AttachBatchFillFeature::class)($palleconB, $batch, ['fill_weight' => 150], $user);

        $this->assertCount(2, $batch->palleconFills()->get());
        $this->assertCount(2, $batch->palleconContainers()->get());
    }

    public function test_fill_beyond_capacity_plus_overfill_is_rejected(): void
    {
        config(['dbmts.pallecon.capacity_kg' => 1100, 'dbmts.pallecon.overfill_tolerance' => 0.10]);
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM260908-04');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-C'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch, ['fill_weight' => 1000], $user);

        $this->expectException(PalleconException::class);
        // 1000 + 250 = 1250 > 1210 (1100 + 10%).
        app(AttachBatchFillFeature::class)($pallecon, $batch, ['fill_weight' => 250], $user);
    }

    public function test_fill_within_overfill_tolerance_is_allowed(): void
    {
        config(['dbmts.pallecon.capacity_kg' => 1100, 'dbmts.pallecon.overfill_tolerance' => 0.10]);
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM260908-05');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-D'], $user);
        // 1200 <= 1210 limit.
        app(AttachBatchFillFeature::class)($pallecon, $batch, ['fill_weight' => 1200], $user);

        $this->assertEqualsWithDelta(1200.0, $pallecon->filledWeight(), 0.001);
    }

    public function test_low_weight_pallecon_is_valid(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM260908-06');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-E'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch, ['fill_weight' => 50], $user);
        $sealed = app(SealPalleconFeature::class)($pallecon, ['final_weight' => 50], $user);

        $this->assertSame(Pallecon::STATUS_SEALED, $sealed->status);
        $this->assertEqualsWithDelta(50.0, (float) $sealed->final_weight, 0.001);
    }

    public function test_sealing_records_final_weight_and_signature(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM260908-07');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-F'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch, ['fill_weight' => 400], $user);
        $sealed = app(SealPalleconFeature::class)($pallecon, [
            'final_weight' => 690,
            'top_seal_number' => 'TS-1',
            'bottom_seal_number' => 'BS-1',
        ], $user);

        $this->assertSame(Pallecon::STATUS_SEALED, $sealed->status);
        $this->assertEqualsWithDelta(690.0, (float) $sealed->final_weight, 0.001);
        $this->assertSame('TS-1', $sealed->top_seal_number);
        $this->assertNotNull($sealed->sealed_at);
        $this->assertDatabaseHas('electronic_signatures', ['entity_name' => 'pallecons', 'signature_purpose' => 'pallecon_sealed']);
    }

    public function test_cannot_seal_without_fills(): void
    {
        $user = User::factory()->create();
        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-G'], $user);

        $this->expectException(PalleconException::class);
        app(SealPalleconFeature::class)($pallecon, ['final_weight' => 100], $user);
    }

    public function test_cannot_fill_a_sealed_pallecon(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM260908-08');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-H'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch, ['fill_weight' => 100], $user);
        app(SealPalleconFeature::class)($pallecon, ['final_weight' => 100], $user);

        $this->expectException(PalleconException::class);
        app(AttachBatchFillFeature::class)($pallecon->refresh(), $batch, ['fill_weight' => 50], $user);
    }

    public function test_reusing_an_active_serial_is_rejected(): void
    {
        $user = User::factory()->create();
        app(OpenPalleconFeature::class)(['serial_number' => 'PAL-REUSE'], $user);

        $this->expectException(PalleconException::class);
        app(OpenPalleconFeature::class)(['serial_number' => 'PAL-REUSE'], $user);
    }

    public function test_serial_can_be_reused_once_consumed(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM260908-09');

        $first = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-CYCLE'], $user);
        app(AttachBatchFillFeature::class)($first, $batch, ['fill_weight' => 100], $user);
        app(SealPalleconFeature::class)($first, ['final_weight' => 100], $user);
        $first->refresh()->update(['status' => Pallecon::STATUS_CONSUMED]);

        $second = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-CYCLE'], $user);
        $this->assertSame(Pallecon::STATUS_OPEN, $second->status);
    }

    public function test_backward_trace_by_serial_returns_all_contributing_batches(): void
    {
        $user = User::factory()->create();
        $batch1 = $this->makeBatch('WM260908-10');
        $batch2 = $this->makeBatch('WM260908-11');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-TRACE'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch1, ['fill_weight' => 400], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch2, ['fill_weight' => 300], $user);

        $results = app(BackwardTraceFeature::class)('PAL-TRACE');
        $batchIds = collect($results)->pluck('batch.id')->all();

        $this->assertContains($batch1->id, $batchIds);
        $this->assertContains($batch2->id, $batchIds);
    }
}
