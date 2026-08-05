<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use App\Models\User;
use App\Operations\SyncMicrosoftUsersOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_settings_admin_page(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');

        $response = $this->actingAs($user)->get(route('settings.admin'));

        $response->assertOk();
        $response->assertSee('Settings Admin');
        $response->assertSee('Feature Toggles');
    }

    public function test_admin_can_save_feature_toggles(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');
        $this->actingAs($user);

        $component = Volt::test('pages.settings.admin');
        $toggles = $component->get('toggles');
        $toggles['allocation.scanner'] = false;
        $toggles['allocation.scanner_debug_panel'] = false;

        $component
            ->set('toggles', $toggles)
            ->call('save')
            ->assertSet('flash', 'Settings saved.')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'key' => 'allocation.scanner',
            'value' => 'false',
            'value_type' => 'boolean',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'allocation.scanner_debug_panel',
            'value' => 'false',
            'value_type' => 'boolean',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'paperwork.signoff_display_mode',
            'value' => 'short_initials',
            'value_type' => 'string',
        ]);
    }

    public function test_admin_can_save_signoff_display_mode(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');
        $this->actingAs($user);

        Volt::test('pages.settings.admin')
            ->set('signoffDisplayMode', 'initial_last_name')
            ->set('microsoftOperatorGroupId', 'f907ccd7-04f4-4c7b-b0f7-18827699aeba')
            ->call('save')
            ->assertSet('flash', 'Settings saved.')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('app_settings', [
            'key' => 'paperwork.signoff_display_mode',
            'value' => 'initial_last_name',
            'value_type' => 'string',
        ]);

        $this->assertDatabaseHas('app_settings', [
            'key' => 'microsoft.operator_group_id',
            'value' => 'f907ccd7-04f4-4c7b-b0f7-18827699aeba',
            'value_type' => 'string',
        ]);
    }

    public function test_admin_can_run_microsoft_user_sync(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');
        $this->actingAs($user);

        $sync = Mockery::mock(SyncMicrosoftUsersOperation::class);
        $sync->shouldReceive('__invoke')->once()->andReturn(['synced' => 4, 'skipped' => 1]);
        $this->instance(SyncMicrosoftUsersOperation::class, $sync);

        Volt::test('pages.settings.admin')
            ->call('syncMicrosoftUsers')
            ->assertSet('flash', 'Microsoft user sync completed. Synced 4 user(s), skipped 1.')
            ->assertSet('flashLevel', 'success')
            ->assertHasNoErrors();
    }

    public function test_admin_can_reset_feature_toggles_to_defaults(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');
        $this->actingAs($user);

        AppSetting::create([
            'key' => 'allocation.scanner',
            'value' => 'false',
            'value_type' => 'boolean',
        ]);

        $component = Volt::test('pages.settings.admin');

        $component
            ->call('resetToDefaults')
            ->assertSet('toggles', fn (array $toggles): bool => ($toggles['allocation.scanner'] ?? null) === true)
            ->assertSet('flash', 'Settings reset to config defaults.')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('app_settings', [
            'key' => 'allocation.scanner',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
