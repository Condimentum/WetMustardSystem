<?php

namespace Tests\Feature\Recipes;

use App\Models\RecipeCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
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

    public function test_admin_can_save_recipe_modal_metadata_and_steps(): void
    {
        Role::findOrCreate('administrator');

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->assignRole('administrator');

        $this->actingAs($user);

        Volt::test('pages.recipes.index')
            ->set('selectedRecipeCode', '30010001')
            ->set('batchSizeInputs', ['800', '1000'])
            ->set('plcRecipeNumber', 'PLC-CPM001WG')
            ->set('documentReference', 'wm023')
            ->set('revisionNo', '3')
            ->set('issueDate', '2026-07-24')
            ->set('reasonForIssue', 'Formula alignment and process clarification.')
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
            'issue_date' => '2026-07-24 00:00:00',
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
