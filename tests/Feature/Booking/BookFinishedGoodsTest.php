<?php

namespace Tests\Feature\Booking;

use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\CallManufacturingOrderFinishingJob;
use App\Domains\WinMan\Jobs\CheckWinManInventoryDuplicateJob;
use App\Domains\WinMan\Jobs\GetWinManProductPackSizeJob;
use App\Domains\WinMan\Jobs\ReadMoBookingContextJob;
use App\Features\Booking\BookFinishedGoodsFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\WinManBookingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BookFinishedGoodsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('winman.booking.enabled', true);
    }

    private function makeBatch(): BatchRecord
    {
        $product = Product::create(['recipe_code' => 'R1', 'product_name' => 'Test', 'active_flag' => true, 'winman_product_id' => '70010081']);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MO00006672', 'winman_manufacturing_order' => 726231, 'winman_manufacturing_order_id' => 'MO00006672',
            'recipe_code' => 'R1', 'product_id' => $product->id, 'winman_product_id' => '70010081',
            'planned_quantity' => 5200, 'quantity_outstanding' => 5200, 'winman_system_type' => 'F', 'status' => 'selected',
        ]);

        return BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => 'WM-B1',
            'production_date' => now()->toDateString(), 'status' => BatchRecord::STATUS_COMPLETED,
        ]);
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

    private function book(BatchRecord $batch): WinManBookingLog
    {
        return app(BookFinishedGoodsFeature::class)(
            $batch, 1000.0, 'IBC-001', ['IBC-001'], now(), now()->addMonths(6), User::factory()->create(),
        );
    }

    public function test_booking_disabled_throws(): void
    {
        config()->set('winman.booking.enabled', false);
        $this->mockWinMan(['finishingCalls' => 0]);

        $this->expectException(WinManException::class);
        $this->book($this->makeBatch());
    }

    public function test_successful_booking_records_a_success_log_with_inventory_id(): void
    {
        $this->mockWinMan(['finishingCalls' => 1]);

        $log = $this->book($this->makeBatch());

        $this->assertSame(WinManBookingLog::STATUS_SUCCESS, $log->booking_status);
        $this->assertSame(987654, $log->winman_inventory_id);
        $this->assertSame('1.000', (string) $log->quantity_booked_traded_units); // 1000kg / 1000 packsize
        $this->assertNull($log->pallecon_id);
    }

    public function test_pallecon_id_is_stored_when_passed(): void
    {
        $this->mockWinMan(['finishingCalls' => 1]);

        $batch = $this->makeBatch();
        $pallecon = \App\Models\Pallecon::create(['serial_number' => 'PAL-BFG-1', 'status' => \App\Models\Pallecon::STATUS_OPEN]);

        $log = app(BookFinishedGoodsFeature::class)(
            $batch, 1000.0, 'IBC-001', ['IBC-001'], now(), now()->addMonths(6), User::factory()->create(), false, $pallecon->id,
        );

        $this->assertSame($pallecon->id, $log->pallecon_id);
    }

    public function test_duplicate_inventory_lot_is_rejected(): void
    {
        $this->mockWinMan(['duplicates' => [['lot' => 'IBC-001', 'matched' => 'IBC-001-X', 'product' => '5']], 'finishingCalls' => 0]);

        $log = $this->book($this->makeBatch());

        $this->assertSame(WinManBookingLog::STATUS_REJECTED, $log->booking_status);
        $this->assertStringContainsString('Duplicate IBC', (string) $log->error_message);
    }

    public function test_already_booked_batch_is_rejected(): void
    {
        $this->mockWinMan(['finishingCalls' => 0]);
        $batch = $this->makeBatch();
        WinManBookingLog::create([
            'batch_record_id' => $batch->id, 'winman_manufacturing_order' => 726231, 'batch_number' => $batch->batch_number,
            'booking_date' => now(), 'booking_status' => WinManBookingLog::STATUS_SUCCESS,
        ]);

        $log = $this->book($batch);

        $this->assertSame(WinManBookingLog::STATUS_REJECTED, $log->booking_status);
        $this->assertStringContainsString('already been booked', (string) $log->error_message);
    }

    public function test_no_outstanding_quantity_is_rejected(): void
    {
        $this->mockWinMan([
            'context' => ['last_modified_date' => '2026-01-01 00:00:00', 'quantity_outstanding' => 0.0, 'quantity' => 5200.0, 'location' => 5],
            'finishingCalls' => 0,
        ]);

        $log = $this->book($this->makeBatch());

        $this->assertSame(WinManBookingLog::STATUS_REJECTED, $log->booking_status);
        $this->assertStringContainsString('outstanding', (string) $log->error_message);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
