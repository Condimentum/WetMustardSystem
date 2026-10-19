<?php

namespace Tests\Feature\Recipes;

use App\Domains\WinMan\Support\WinManConnection;
use App\Models\RecipeCard;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecipesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_recipes_page(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');

        $response = $this->actingAs($user)->get(route('settings.recipes'));

        $response->assertOk();
        $response->assertSee('Recipes');
        $response->assertSee('Live WinMan recipe structures');
    }

    public function test_recipe_list_is_split_into_active_and_inactive(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('administrator');

        $structure = fn (int $id, string $code, string $description): object => (object) [
            'StructureProduct' => $id,
            'StructureProductId' => $code,
            'StructureClassification' => 30,
            'StructureProductDescription' => $description,
            'ComponentProduct' => 1,
            'ComponentProductId' => 'C1',
            'ComponentProductDescription' => 'Component',
            'QuantityPerUnit' => 1,
            'ComponentValidFrom' => null,
            'ComponentValidTo' => null,
            'StructureLevel' => 1,
        ];

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('select')->andReturn([
            $structure(307, '30010001', 'RECIPE With Batch'),
            $structure(309, '30010002', 'RECIPE With Steps'),
            $structure(310, '30010003', 'RECIPE With PLC'),
            $structure(311, '30010004', 'RECIPE Empty Card'),
            $structure(312, '30010005', 'RECIPE No Card'),
        ]);
        $winman = Mockery::mock(WinManConnection::class);
        $winman->shouldReceive('connection')->andReturn($connection);
        $this->app->instance(WinManConnection::class, $winman);

        RecipeCard::create(['recipe_code' => '30010001', 'batch_sizes_kg' => [800]]);
        RecipeCard::create(['recipe_code' => '30010002', 'steps' => ['Charge water']]);
        RecipeCard::create(['recipe_code' => '30010003', 'plc_recipe_number' => 'PLC-1']);
        RecipeCard::create(['recipe_code' => '30010004']);

        $component = Volt::actingAs($user)->test('pages.recipes.index');

        $rows = collect($component->get('recipeRows'))->keyBy('structure_product_id');

        $this->assertTrue($rows['30010001']['is_active']);
        $this->assertTrue($rows['30010002']['is_active']);
        $this->assertTrue($rows['30010003']['is_active']);
        $this->assertFalse($rows['30010004']['is_active']);
        $this->assertFalse($rows['30010005']['is_active']);

        $component
            ->assertSeeInOrder(['Active Recipes', 'RECIPE With Batch', 'RECIPE With Steps', 'RECIPE With PLC', 'Inactive Recipes', 'RECIPE Empty Card', 'RECIPE No Card']);
    }

    public function test_admin_can_save_recipe_modal_metadata_and_steps(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');

        $this->actingAs($user);

        // Document reference / revision / issue date / reason belong to the linked document, so saving here leaves them alone.
        RecipeCard::create(['recipe_code' => '30010001', 'document_reference' => 'WM023', 'revision_no' => '3']);

        Volt::test('pages.recipes.index')
            ->set('selectedRecipeCode', '30010001')
            ->set('batchSizeInputs', ['800', '1000'])
            ->set('plcRecipeNumber', 'PLC-CPM001WG')
            ->set('stepInputs', ['Charge water', 'Add vinegar', 'Add salt', 'Add mustard seeds'])
            ->call('saveRecipeModal')
            ->assertHasNoErrors()
            ->assertRedirect(route('settings.recipes'));

        $this->assertDatabaseHas('recipe_cards', [
            'recipe_code' => '30010001',
            'batch_size_kg' => 800,
            'plc_recipe_number' => 'PLC-CPM001WG',
            'document_reference' => 'WM023',
            'revision_no' => '3',
        ]);

        $card = RecipeCard::query()->where('recipe_code', '30010001')->first();
        $this->assertNotNull($card);
        $this->assertSame([800, 1000], $card?->batch_sizes_kg);
        $this->assertSame(
            ['Charge water', 'Add vinegar', 'Add salt', 'Add mustard seeds'],
            $card?->steps,
        );
    }
}
