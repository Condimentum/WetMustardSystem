<?php

namespace Tests\Feature;

use App\Domains\WinMan\Data\ManufacturingOrderData;
use App\Domains\WinMan\Jobs\SearchOutstandingManufacturingOrdersJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductionOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function order(int $id, string $ref, int $classification, int $uom): ManufacturingOrderData
    {
        return new ManufacturingOrderData(
            winmanManufacturingOrder: $id,
            winmanManufacturingOrderId: $ref,
            winmanProductInternal: 100 + $id,
            winmanProductId: '7001000'.$id,
            productDescription: 'Product '.$ref,
            systemType: 'F',
            plannedQuantity: 100.0,
            quantityOutstanding: 40.0,
            classification: $classification,
            unitOfMeasure: $uom,
            unitOfMeasureDescription: null,
            dueDate: '2026-07-10 00:00:00',
            lastModifiedDate: null,
        );
    }

    private function mockOrders(): void
    {
        $mock = Mockery::mock(SearchOutstandingManufacturingOrdersJob::class);
        $mock->shouldReceive('__invoke')->with(null, 250)->andReturn([
            $this->order(1, 'MO-INT', 30, 10),
            $this->order(2, 'MO-IBC', 29, 2),
            $this->order(3, 'MO-BUCKET', 29, 44),
        ]);

        $this->app->instance(SearchOutstandingManufacturingOrdersJob::class, $mock);
    }

    public function test_packed_page_shows_all_classification_29_orders_regardless_of_uom(): void
    {
        $this->mockOrders();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('production.packed'));

        $response->assertOk();
        $response->assertSee('MO-IBC');
        $response->assertSee('MO-BUCKET');
        $response->assertDontSee('MO-INT');
    }

    public function test_calibrations_and_quality_pages_are_available(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('calibrations.daily'))
            ->assertOk()
            ->assertSee('DAILY CALIBRATIONS');

        $this->actingAs($user)->get(route('quality.lab-testing'))
            ->assertOk()
            ->assertSee('QUALITY &amp; LAB TESTING', false);
    }
}
