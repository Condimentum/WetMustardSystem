<?php

namespace Tests\Feature\ManufacturingOrders;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

/**
 * The MO Workspace "Pallecon Workspace" panel: a list of this MO's pallecons
 * plus a weight-only add form that opens an empty pallecon.
 */
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

    public function test_add_pallecon_opens_an_empty_container_with_a_target_and_production_date(): void
    {
        $order = $this->setUpMo(7300);
        $this->actingAs(User::factory()->create());

        Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7300])
            ->assertOk()
            ->set('palleconWeight', '1000')
            ->call('createPallecon')
            ->assertHasNoErrors();

        $pallecon = Pallecon::where('manufacturing_order_id', $order->id)->first();
        $this->assertNotNull($pallecon);
        $this->assertSame(Pallecon::STATUS_OPEN, $pallecon->status);
        $this->assertSame('1000.000', (string) $pallecon->target_weight_kg);
        $this->assertSame(now()->toDateString(), $pallecon->production_date->toDateString());
        $this->assertNull($pallecon->winman_reference);
        $this->assertSame(0, $pallecon->fills()->count());
    }

    public function test_only_one_open_pallecon_per_mo(): void
    {
        $order = $this->setUpMo(7301);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7301])
            ->set('palleconWeight', '900')
            ->call('createPallecon')
            ->assertHasNoErrors();

        $component->set('palleconWeight', '500')->call('createPallecon');

        $this->assertSame(1, Pallecon::where('manufacturing_order_id', $order->id)->count());
        $this->assertStringContainsString('already open for this MO', (string) $component->get('palleconError'));
    }

    public function test_target_weight_cannot_exceed_the_pallecon_capacity_limit(): void
    {
        $order = $this->setUpMo(7302);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7302])
            ->set('palleconWeight', '5000')
            ->call('createPallecon');

        $this->assertSame(0, Pallecon::where('manufacturing_order_id', $order->id)->count());
        $this->assertStringContainsString('above the', (string) $component->get('palleconError'));
    }

    public function test_the_pallecon_row_links_to_its_detail_page(): void
    {
        $order = $this->setUpMo(7303);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('pages.manufacturing-orders.workspace', ['winmanMo' => 7303])
            ->set('palleconWeight', '800')
            ->call('createPallecon');

        $pallecon = Pallecon::where('manufacturing_order_id', $order->id)->first();
        $component->assertSee(route('manufacturing-orders.pallecons', ['winmanMo' => 7303, 'pallecon' => $pallecon->id]), escape: false);
    }
}
