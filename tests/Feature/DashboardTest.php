<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_main_menu_tiles(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Wet Mustard Booking System');
        $response->assertSee('Daily Calibrations');
        $response->assertSee('Metal Detections');
        $response->assertSee('Quality &amp; Lab Testing', false);
        $response->assertSee('Wet Mustard - Manufacturing');
        $response->assertSee('Wet Mustard - Packed');
        $response->assertSee(route('metal-detector.daily'));
        $response->assertSee(route('manufacturing-orders.search'));
        $response->assertSee(route('production.packed'));
        $response->assertSee(route('calibrations.daily'));
        $response->assertSee(route('quality.lab-testing'));
    }
}
