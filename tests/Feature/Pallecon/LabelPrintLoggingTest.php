<?php

namespace Tests\Feature\Pallecon;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Jobs\FetchWetMustardLabelDataJob;
use App\Domains\WinMan\Jobs\ResolveWetMustardRecordPickerTokenJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\OpenPalleconFeature;
use App\Features\Pallecon\SealPalleconFeature;
use App\Models\BatchRecord;
use App\Models\LabelPrintLog;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

/**
 * Printing was previously fire-and-forget with no persisted history. This
 * covers that every print attempt (success or failure) is now recorded to
 * label_print_logs, which the batch's view-only screen reads from.
 */
class LabelPrintLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $health = Mockery::mock(WinManHealthCheck::class);
        $health->shouldReceive('isUp')->andReturn(true);
        $this->instance(WinManHealthCheck::class, $health);

        $job = Mockery::mock(FetchManufacturingOrderJob::class);
        $job->shouldReceive('__invoke')->andReturn(new ManufacturingOrderData(
            winmanManufacturingOrder: 8001,
            winmanManufacturingOrderId: 'MO8001',
            winmanProductInternal: 1,
            winmanProductId: '50010007',
            productDescription: 'Test Mustard',
            systemType: 'F',
            plannedQuantity: 800,
            quantityOutstanding: 800,
            classification: 30,
            unitOfMeasure: 2,
            unitOfMeasureDescription: 'PALLECON',
            dueDate: null,
            lastModifiedDate: null,
        ));
        $this->instance(FetchManufacturingOrderJob::class, $job);

        $labelData = Mockery::mock(FetchWetMustardLabelDataJob::class);
        $labelData->shouldReceive('__invoke')->andReturn([]);
        $this->instance(FetchWetMustardLabelDataJob::class, $labelData);

        $token = Mockery::mock(ResolveWetMustardRecordPickerTokenJob::class);
        $token->shouldReceive('__invoke')->andReturn(null);
        $this->instance(ResolveWetMustardRecordPickerTokenJob::class, $token);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function sealedPallecon(): Pallecon
    {
        $product = Product::create(['recipe_code' => 'RLBL', 'product_name' => 'Test Mustard', 'winman_product_id' => '50010007', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MO8001', 'winman_manufacturing_order' => 8001, 'winman_manufacturing_order_id' => 'MO8001',
            'winman_product_id' => '50010007', 'recipe_code' => 'RLBL', 'product_id' => $product->id,
            'planned_quantity' => 800, 'quantity_outstanding' => 800, 'winman_system_type' => 'F', 'status' => 'selected',
        ]);
        $batch = BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => 'WM-LBL-01',
            'production_date' => now()->toDateString(), 'planned_quantity' => 400, 'status' => BatchRecord::STATUS_COMPLETED,
        ]);

        $user = User::factory()->create();
        $pallecon = app(OpenPalleconFeature::class)([
            'manufacturing_order_id' => $order->id, 'mo_number' => $order->mo_number,
            'serial_number' => 'PAL-LBL-1', 'target_weight_kg' => 400, 'production_date' => now()->toDateString(),
        ], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch->load('manufacturingOrder', 'product'), ['fill_weight' => 400], $user);

        return app(SealPalleconFeature::class)($pallecon->fresh(), ['final_weight' => 400], $user);
    }

    public function test_a_failed_print_attempt_is_recorded(): void
    {
        // BarTender is disabled by default in tests, so the client throws before
        // any HTTP call - a deterministic failure path to prove the log is written.
        config(['services.bartender.enabled' => false]);
        $sealed = $this->sealedPallecon();

        $this->actingAs(User::factory()->create());
        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 8001])
            ->call('printLabel', $sealed->id);

        $this->assertDatabaseHas('label_print_logs', [
            'pallecon_id' => $sealed->id,
            'serial_number' => 'PAL-LBL-1',
            'status' => LabelPrintLog::STATUS_FAILED,
        ]);

        $log = LabelPrintLog::where('pallecon_id', $sealed->id)->firstOrFail();
        $this->assertStringContainsString('BarTender integration is disabled', (string) $log->error_message);
        $this->assertSame('WM-LBL-01', $log->batchRecord?->batch_number);
    }

    public function test_a_successful_print_attempt_is_recorded_with_label_data(): void
    {
        config([
            'services.bartender.enabled' => true,
            'services.bartender.base_url' => 'http://bartender.test',
            'services.bartender.username' => 'user',
            'services.bartender.password' => 'pass',
            'services.bartender.library_id' => 'LIB1',
            'services.bartender.default_printer' => 'Printer1',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*Authenticate*' => \Illuminate\Support\Facades\Http::response(['token' => 'tok-123'], 200),
            '*print*' => \Illuminate\Support\Facades\Http::response(['messages' => ['Printer: Printer1']], 200),
        ]);

        $sealed = $this->sealedPallecon();

        $this->actingAs(User::factory()->create());
        Volt::test('pages.manufacturing-orders.pallecon-workspace', ['winmanMo' => 8001])
            ->call('printLabel', $sealed->id);

        $this->assertDatabaseHas('label_print_logs', [
            'pallecon_id' => $sealed->id,
            'status' => LabelPrintLog::STATUS_SUCCESS,
        ]);

        $log = LabelPrintLog::where('pallecon_id', $sealed->id)->firstOrFail();
        $this->assertIsArray($log->label_data);
        $this->assertSame('WM-LBL-01', $log->label_data['BatchNumber'] ?? null);
    }
}
