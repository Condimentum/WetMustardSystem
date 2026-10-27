<?php

namespace Tests\Feature\Batches;

use App\Domains\WinMan\Jobs\FindProductForLotJob;
use App\Features\Batches\GetAvailableIngredientLotsFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class BatchSsccScanAllocationTest extends TestCase
{
    use RefreshDatabase;

    private const SSCC = '350607255023009858';

    private function makeBatch(): BatchRecord
    {
        $product = Product::create(['recipe_code' => '30010001', 'product_name' => 'Test Mustard', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MO-SSCC-1', 'winman_manufacturing_order' => 930001, 'winman_manufacturing_order_id' => 'MO-SSCC-1',
            'recipe_code' => '30010001', 'product_id' => $product->id, 'planned_quantity' => 1000,
            'quantity_outstanding' => 1000, 'winman_classification' => 30, 'winman_system_type' => 'F', 'status' => 'selected',
        ]);

        return BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => 'MO-SSCC-1-100018-01',
            'production_date' => now()->toDateString(), 'planned_quantity' => 500, 'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);
    }

    private function scanComponent(array $lots, ?array $knownLot, string $raw)
    {
        $feature = Mockery::mock(GetAvailableIngredientLotsFeature::class);
        $feature->shouldReceive('__invoke')->with('100018', 5, self::SSCC)->andReturn($lots);
        $this->app->instance(GetAvailableIngredientLotsFeature::class, $feature);

        $finder = Mockery::mock(FindProductForLotJob::class);
        $finder->shouldReceive('__invoke')->with(self::SSCC)->andReturn($knownLot);
        $this->app->instance(FindProductForLotJob::class, $finder);

        $this->actingAs(User::factory()->create());

        return Volt::test('pages.batches.show', ['batch' => $this->makeBatch()])
            ->set('featureAllocationScannerEnabled', true)
            ->set('featureAllocationWinmanLookupEnabled', true)
            ->set('activeBomMaterialCode', '100018')
            ->set('activeBomGrScanRaw', $raw)
            ->call('applyGrScanPayload', 'sscc');
    }

    public function test_sscc_scan_selects_the_winman_lot_and_adds_it_to_the_options(): void
    {
        $this->scanComponent(
            [['lot_number' => self::SSCC, 'quantity_outstanding' => 750.0]],
            null,
            '00'.self::SSCC.'37000000010',
        )
            ->assertSet('activeBomLotNumber', self::SSCC)
            ->assertSet('activeBomMessage', null)
            ->assertSet('activeBomLotOptions', [['lot_number' => self::SSCC, 'quantity_outstanding' => 750.0]]);
    }

    public function test_sscc_for_a_different_product_is_refused(): void
    {
        $this->scanComponent(
            [],
            ['product_id' => '200045', 'product_description' => 'Other Ingredient', 'quantity_outstanding' => 10.0],
            self::SSCC,
        )
            ->assertSet('activeBomLotNumber', null)
            ->assertSet('activeBomMessage', 'This SSCC is for a different product than this BOM line.');
    }

    public function test_unknown_sscc_with_a_valid_check_digit_is_reported_as_not_in_stock(): void
    {
        $this->scanComponent([], null, self::SSCC)
            ->assertSet('activeBomLotNumber', null)
            ->assertSet('activeBomMessage', 'This SSCC is not in WinMan stock. Check the label, or allocate by lot instead.');
    }

    public function test_a_qr_label_scanned_in_sscc_mode_is_rejected(): void
    {
        $this->scanComponent([], null, '100018^Cracked Mustard^LOT1^')
            ->assertSet('activeBomMessage', 'That is a QR label, not an SSCC barcode. Switch the scanner to QR, or scan the SSCC barcode.');
    }
}
