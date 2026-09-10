<?php

namespace Tests\Feature\ManufacturingOrders;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Models\BatchIngredientLot;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\PaperworkRow;
use App\Models\Pallecon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class WorkspacePalleconTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function setUpMo(int $winmanMo): ManufacturingOrder
    {
        $health = Mockery::mock(WinManHealthCheck::class);
        $health->shouldReceive('isUp')->andReturn(true);
        $this->instance(WinManHealthCheck::class, $health);

        $job = Mockery::mock(FetchManufacturingOrderJob::class);
        $job->shouldReceive('__invoke')->andReturn(new ManufacturingOrderData(
            winmanManufacturingOrder: $winmanMo,
            winmanManufacturingOrderId: 'MO'.$winmanMo,
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

        $product = Product::create(['recipe_code' => 'RWP', 'product_name' => 'Test Mustard', 'winman_product_id' => '50010007', 'active_flag' => true]);

        return ManufacturingOrder::create([
            'mo_number' => 'MO'.$winmanMo, 'winman_manufacturing_order' => $winmanMo, 'winman_manufacturing_order_id' => 'MO'.$winmanMo,
            'winman_product_id' => '50010007', 'recipe_code' => 'RWP', 'product_id' => $product->id,
            'planned_quantity' => 800, 'quantity_outstanding' => 800, 'winman_classification' => 30,
            'winman_system_type' => 'F', 'status' => 'selected',
        ]);
    }

    private function signedOffBatch(ManufacturingOrder $order, string $ref, float $planned = 800, bool $signedOff = true): BatchRecord
    {
        $batch = BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $order->product_id, 'batch_number' => $ref,
            'production_date' => now()->toDateString(), 'planned_quantity' => $planned, 'status' => BatchRecord::STATUS_COMPLETED,
        ]);
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
                PaperworkRow::create([
                    'manufacturing_order_id' => $order->id, 'batch_record_id' => $batch->id,
                    'batch_number' => $ref, 'batch_column_index' => 1,
                    'row_key' => $rowKey, 'row_label' => $label, 'row_order' => $rowOrder,
                    'value_text' => 'Op', 'status' => 'completed',
                ]);
            }
        }

        return $batch;
    }

    public function test_add_pallecon_creates_the_container_with_its_first_fill(): void
    {
        $order = $this->setUpMo(7300);
        $batch = $this->signedOffBatch($order, 'WM-WP-01');
        $this->actingAs(User::factory()->create());

        Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7300])
            ->assertOk()
            ->set('palleconNumber', 'PAL-WP-1')
            ->set('palleconBatchId', (string) $batch->id)
            ->set('palleconFillWeight', '400')
            ->call('createPallecon')
            ->assertHasNoErrors()
            ->assertSee('PAL-WP-1');

        $pallecon = Pallecon::where('serial_number', 'PAL-WP-1')->first();
        $this->assertNotNull($pallecon);
        $this->assertSame(Pallecon::STATUS_FILLING, $pallecon->status);
        $this->assertDatabaseHas('pallecon_fills', ['pallecon_id' => $pallecon->id, 'batch_record_id' => $batch->id, 'fill_weight' => 400]);
    }

    public function test_only_one_open_pallecon_per_mo(): void
    {
        $order = $this->setUpMo(7301);
        $batch = $this->signedOffBatch($order, 'WM-WP-02');
        $this->actingAs(User::factory()->create());

        $component = Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7301])
            ->set('palleconNumber', 'PAL-WP-2A')
            ->set('palleconBatchId', (string) $batch->id)
            ->set('palleconFillWeight', '300')
            ->call('createPallecon')
            ->assertHasNoErrors();

        $component
            ->set('palleconNumber', 'PAL-WP-2B')
            ->set('palleconBatchId', (string) $batch->id)
            ->set('palleconFillWeight', '100')
            ->call('createPallecon');

        $this->assertNull(Pallecon::where('serial_number', 'PAL-WP-2B')->first());
        $this->assertStringContainsString('already open for this MO', (string) $component->get('palleconError'));
    }

    public function test_fill_weight_cannot_exceed_the_batch_planned_quantity(): void
    {
        $order = $this->setUpMo(7302);
        $batch = $this->signedOffBatch($order, 'WM-WP-03', planned: 800);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7302])
            ->set('palleconNumber', 'PAL-WP-3')
            ->set('palleconBatchId', (string) $batch->id)
            ->set('palleconFillWeight', '900')
            ->call('createPallecon');

        $this->assertDatabaseCount('pallecons', 0);
        $this->assertStringContainsString('remaining on batch', (string) $component->get('palleconError'));
    }

    public function test_a_batch_from_another_mo_cannot_be_used(): void
    {
        $this->setUpMo(7303);
        $this->setUpMo(7304);
        $otherBatch = BatchRecord::create([
            'manufacturing_order_id' => ManufacturingOrder::where('winman_manufacturing_order', 7304)->value('id'),
            'batch_number' => 'WM-WP-OTHER', 'production_date' => now()->toDateString(),
            'planned_quantity' => 800, 'status' => BatchRecord::STATUS_COMPLETED,
        ]);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7303])
            ->set('palleconNumber', 'PAL-WP-4')
            ->set('palleconBatchId', (string) $otherBatch->id)
            ->set('palleconFillWeight', '100')
            ->call('createPallecon');

        $this->assertDatabaseCount('pallecons', 0);
        $this->assertStringContainsString('Select a batch from this manufacturing order', (string) $component->get('palleconError'));
    }
}
