<?php

namespace Tests\Feature\ManufacturingOrders;

use App\Features\ManufacturingOrders\SearchManufacturingOrdersFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class ManufacturingOrderSearchScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mo_search_screen_renders_for_an_authenticated_user(): void
    {
        $search = Mockery::mock(SearchManufacturingOrdersFeature::class);
        $search->shouldReceive('__invoke')->andReturn([]);
        $this->instance(SearchManufacturingOrdersFeature::class, $search);

        $this->actingAs(User::factory()->create());

        Volt::test('pages.manufacturing-orders.search')
            ->assertOk()
            ->assertSee('WET MUSTARD - MANUFACTURING')
            ->assertSee('No eligible outstanding MOs found.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
