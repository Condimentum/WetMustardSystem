<?php

namespace Tests\Feature\Settings;

use App\Operations\SyncMicrosoftUsersOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncMicrosoftUsersOperationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_enabled_microsoft_users_without_resetting_existing_passwords(): void
    {
        config()->set('services.microsoft.tenant_id', 'tenant-id');
        config()->set('services.microsoft.client_id', 'client-id');
        config()->set('services.microsoft.client_secret', 'client-secret');
        config()->set('services.microsoft.operator_group_id', 'group-id');

        $existing = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'alex@example.com',
            'password' => 'secret-password',
        ]);
        $existingHash = $existing->password;

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'graph-token',
            ], 200),
            'https://graph.microsoft.com/v1.0/groups/group-id/members/microsoft.graph.user*' => Http::response([
                'value' => [
                    [
                        'displayName' => 'Alex Smith',
                        'mail' => 'alex@example.com',
                        'userPrincipalName' => 'alex@example.com',
                        'accountEnabled' => true,
                    ],
                    [
                        'displayName' => 'Jordan Brown',
                        'mail' => null,
                        'userPrincipalName' => 'jordan@example.com',
                        'accountEnabled' => true,
                    ],
                    [
                        'displayName' => 'Disabled User',
                        'mail' => 'disabled@example.com',
                        'userPrincipalName' => 'disabled@example.com',
                        'accountEnabled' => false,
                    ],
                ],
            ], 200),
        ]);

        $result = app(SyncMicrosoftUsersOperation::class)();

        $this->assertSame(['synced' => 2, 'skipped' => 1], $result);

        $existing->refresh();
        $this->assertSame('Alex Smith', $existing->name);
        $this->assertSame($existingHash, $existing->password);
        $this->assertTrue(Hash::check('secret-password', $existing->password));

        $this->assertDatabaseHas('users', [
            'email' => 'jordan@example.com',
            'name' => 'Jordan Brown',
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'disabled@example.com',
        ]);
    }

    public function test_it_requires_microsoft_configuration(): void
    {
        config()->set('services.microsoft.tenant_id', null);
        config()->set('services.microsoft.client_id', null);
        config()->set('services.microsoft.client_secret', null);
        config()->set('services.microsoft.operator_group_id', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Microsoft sync is not configured.');

        app(SyncMicrosoftUsersOperation::class)();
     }

    public function test_it_requires_operator_group_configuration(): void
    {
        config()->set('services.microsoft.tenant_id', 'tenant-id');
        config()->set('services.microsoft.client_id', 'client-id');
        config()->set('services.microsoft.client_secret', 'client-secret');
        config()->set('services.microsoft.operator_group_id', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Microsoft operator group is not configured.');

        app(SyncMicrosoftUsersOperation::class)();
    }
 }
