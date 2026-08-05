<?php

namespace Tests\Feature\Settings;

use App\Domains\WinMan\Support\WinManConnection;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductMappingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_product_mapping_page(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');

        $response = $this->actingAs($user)->get(route('settings.product-mapping'));

        $response->assertOk();
        $response->assertSee('Product Mapping');
        $response->assertSee('Stored WinMan structure-to-recipe mapping snapshot');
    }

    public function test_admin_can_sync_product_mapping_snapshot_from_winman(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');
        $this->actingAs($user);

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function (string $sql, array $bindings): bool {
                return str_contains($sql, 'FROM Structures AS S')
                    && str_contains($sql, "P.Classification IN ('29', '30')")
                    && $bindings === [500, '3001%', '9900%'];
            })
            ->andReturn([
                (object) [
                    'StructureProduct' => 242,
                    'StructureProductId' => '70010026',
                    'StructureClassification' => '29',
                    'StructureProductDescription' => 'Condimentum - Wholegrain Mustard CPM001WG (10kg)',
                    'StructureUnitOfMeasureDescription' => '10 Kg',
                    'StructureBoxesPerPallet' => 60,
                    'StructureBatchSizeKG' => 10.0,
                    'ComponentProduct' => 241,
                    'ComponentClassification' => '30',
                    'ComponentProductId' => '50010007',
                    'ComponentProductDescription' => 'Condimentum - Wholegrain Mustard CPM001WG',
                    'QuantityPerUnit' => 10.2,
                    'ComponentValidFrom' => '2079-06-06 00:00:00',
                    'ComponentValidTo' => '2079-06-06 00:00:00',
                    'StructureLevel' => 1,
                ],
                (object) [
                    'StructureProduct' => 241,
                    'StructureProductId' => '50010007',
                    'StructureClassification' => '30',
                    'StructureProductDescription' => 'Condimentum - Wholegrain Mustard CPM001WG',
                    'StructureUnitOfMeasureDescription' => 'Kilogram',
                    'StructureBoxesPerPallet' => 1,
                    'StructureBatchSizeKG' => 1.0,
                    'ComponentProduct' => 307,
                    'ComponentClassification' => '30',
                    'ComponentProductId' => '30010001',
                    'ComponentProductDescription' => 'RECIPE Wholegrain Mustard (CPM001WG) - Condimentum',
                    'QuantityPerUnit' => 1.05,
                    'ComponentValidFrom' => '2079-06-06 00:00:00',
                    'ComponentValidTo' => '2079-06-06 00:00:00',
                    'StructureLevel' => 2,
                ],
            ]);

        $winManConnection = Mockery::mock(WinManConnection::class);
        $winManConnection->shouldReceive('connection')->once()->andReturn($connection);

        $this->app->instance(WinManConnection::class, $winManConnection);

        Volt::test('pages.settings.product-mapping')
            ->call('syncMappings')
            ->assertSet('flash', 'Product mappings synced: 2 rows stored.')
            ->assertSet('summary', fn (array $summary): bool => $summary['mapped_products'] === 2 && $summary['stored_rows'] === 2 && $summary['resolved_recipes'] === 2 && $summary['mapping_issues'] === 0)
            ->assertSet('resolvedRows', fn (array $rows): bool => collect($rows)->contains(
                fn (array $row): bool => $row['structure_product_id'] === '70010026'
                    && $row['structure_unit_of_measure_description'] === '10 Kg'
                    && (int) $row['structure_boxes_per_pallet'] === 60
                    && (float) $row['structure_batch_size_kg'] === 10.0
                    && (bool) ($row['has_mapping_issue'] ?? true) === false
                    && $row['recipe_product_id'] === '30010001'
            ))
            ->assertHasNoErrors();

        $this->assertDatabaseCount('product_mappings', 2);

        $this->assertDatabaseHas('product_mappings', [
            'structure_product_id' => '70010026',
            'structure_unit_of_measure_description' => '10 Kg',
            'structure_boxes_per_pallet' => 60,
            'structure_batch_size_kg' => 10,
            'component_product_id' => '50010007',
            'structure_level' => 1,
        ]);

        $this->assertDatabaseHas('product_mappings', [
            'structure_product_id' => '50010007',
            'component_product_id' => '30010001',
            'structure_level' => 2,
        ]);
    }
}
