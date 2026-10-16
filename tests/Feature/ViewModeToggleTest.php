<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewModeToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_layout_includes_view_mode_toggle_and_boot_script(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('wm-view-toggle', false);
        $response->assertSee('wmViewMode', false);
    }

    public function test_login_page_includes_view_mode_toggle_and_boot_script(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('wm-view-toggle', false);
        $response->assertSee('wmViewMode', false);
    }
}
