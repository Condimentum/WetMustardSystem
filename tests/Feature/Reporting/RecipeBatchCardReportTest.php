<?php

namespace Tests\Feature\Reporting;

use App\Domains\Reporting\ReportRegistry;
use App\Domains\Reporting\Reports\RecipeBatchCardReport;
use App\Domains\Reporting\Support\DocumentSources;
use App\Models\BatchRecord;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeCard;
use App\Models\RecipeIngredient;
use App\Models\ReportConfig;
use App\Models\ReportRecipient;
use App\Models\ReportSendLog;
use App\Models\User;
use App\Operations\SendOffice365MailOperation;
use App\Operations\SendReportOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecipeBatchCardReportTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        RecipeCard::create([
            'recipe_code' => '30010010',
            'document_reference' => 'WM022',
            'plc_recipe_number' => '2',
            'steps' => ['VINEGAR & WATER ADDITION - AGITATOR ON', 'DRY INGREDIENT ADDITION - AGITATOR ON'],
        ]);

        $recipe = Recipe::create(['recipe_code' => '30010010', 'revision' => '8']);
        RecipeIngredient::create(['recipe_id' => $recipe->id, 'material_code' => '10010016', 'material_description' => 'Spirit Vinegar 14%', 'percentage' => 16, 'required_quantity' => 125.6, 'uom' => 'KG', 'sequence' => 1]);
        RecipeIngredient::create(['recipe_id' => $recipe->id, 'material_code' => '10010013', 'material_description' => 'Salt PDV (British)', 'percentage' => 5.8, 'required_quantity' => 45.53, 'uom' => 'KG', 'sequence' => 2]);
    }

    private function batchFor(string $recipeCode, int $classification = 30, int $uom = 1, ?ManufacturingOrder $order = null): BatchRecord
    {
        $this->sequence++;
        $product = Product::firstOrCreate(['recipe_code' => $recipeCode], ['product_name' => 'Dijon Mustard', 'active_flag' => true]);

        $order ??= ManufacturingOrder::create([
            'mo_number' => 'MO'.$this->sequence,
            'winman_manufacturing_order' => 6000 + $this->sequence,
            'winman_manufacturing_order_id' => 'MO0000'.(6000 + $this->sequence),
            'recipe_code' => $recipeCode,
            'product_id' => $product->id,
            'planned_quantity' => 785,
            'quantity_outstanding' => 785,
            'winman_classification' => $classification,
            'winman_unit_of_measure' => $uom,
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);

        return BatchRecord::create([
            'manufacturing_order_id' => $order->id,
            'product_id' => $product->id,
            'batch_number' => 'WM-B'.$this->sequence,
            'production_date' => now()->toDateString(),
            'planned_quantity' => 785,
            'status' => BatchRecord::STATUS_COMPLETED,
        ]);
    }

    private function linkedDocument(): DocumentReference
    {
        $document = DocumentReference::create([
            'code' => 'WM022',
            'title' => 'Dijon Mustard Batch Card',
            'version' => '8',
            'source_type' => DocumentSources::TYPE_RECIPE,
            'recipe_code' => '30010010',
            'status' => 'Active',
        ]);

        DocumentReferenceChange::create([
            'document_reference_id' => $document->id,
            'issue_version' => '9',
            'date_issued' => '2026-10-01',
            'reason_for_change' => 'Increased batch size for sieve throughput',
        ]);

        return $document;
    }

    public function test_document_links_to_the_recipe_whose_card_names_it(): void
    {
        $document = DocumentReference::create(['code' => 'WM022', 'title' => 'Dijon', 'status' => 'Active']);

        $this->assertSame(
            ['type' => DocumentSources::TYPE_RECIPE, 'recipe_code' => '30010010', 'program_key' => null],
            app(DocumentSources::class)->linkFor($document),
        );
    }

    public function test_one_run_per_manufacturing_mo_of_the_recipe(): void
    {
        $this->linkedDocument();

        $first = $this->batchFor('30010010');
        $this->batchFor('30010010', order: $first->manufacturingOrder); // second batch, same MO
        $second = $this->batchFor('30010010');
        $this->batchFor('30010099');                 // other recipe
        $this->batchFor('30010010', classification: 29); // packing MO
        $this->batchFor('30010010', uom: 2);         // pallecon intermediate

        $report = app(ReportRegistry::class)->get('doc_WM022');
        $this->assertInstanceOf(RecipeBatchCardReport::class, $report);

        $this->assertEqualsCanonicalizing([
            'doc_WM022@'.$first->manufacturingOrder->winman_manufacturing_order_id,
            'doc_WM022@'.$second->manufacturingOrder->winman_manufacturing_order_id,
        ], $report->runKeys(now()->startOfDay(), now()->endOfDay()));
    }

    public function test_run_builds_the_mo_batch_card_with_document_control_from_the_document(): void
    {
        $this->linkedDocument();
        $batch = $this->batchFor('30010010');
        $this->batchFor('30010010', order: $batch->manufacturingOrder);
        $mo = $batch->manufacturingOrder->winman_manufacturing_order_id;

        /** @var RecipeBatchCardReport $report */
        $report = app(ReportRegistry::class)->get('doc_WM022@'.$mo);
        $payload = $report->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(2, $payload['row_count']);
        $this->assertStringContainsString($mo, $payload['subject']);
        $this->assertFileExists($payload['attachments'][0]['path']);

        $sections = $report->sections(BatchRecord::query()->with(\App\Domains\Reporting\Support\BatchCardBuilder::BATCH_RELATIONS)->get());
        $this->assertCount(1, $sections); // both batches on one MO sheet
        $this->assertSame('9', $sections[0]['revision_no']);
        $this->assertSame('01/10/2026', $sections[0]['issue_date']);
        $this->assertSame('Increased batch size for sieve throughput', $sections[0]['reason_for_issue']);
        $this->assertStringStartsWith('WM022 - 30010010', $sections[0]['header_reference_line']);
        $this->assertSame('Spirit Vinegar 14%', $sections[0]['components'][0]['description']);
        $this->assertCount(2, $sections[0]['steps']);
    }

    public function test_a_different_batch_size_starts_its_own_sheet(): void
    {
        $this->linkedDocument();
        $first = $this->batchFor('30010010');
        $this->batchFor('30010010', order: $first->manufacturingOrder);
        $smaller = $this->batchFor('30010010', order: $first->manufacturingOrder);
        $smaller->update(['planned_quantity' => 500]);

        /** @var RecipeBatchCardReport $report */
        $report = app(ReportRegistry::class)->get('doc_WM022@'.$first->manufacturingOrder->winman_manufacturing_order_id);
        $sections = $report->sections(BatchRecord::query()->with(\App\Domains\Reporting\Support\BatchCardBuilder::BATCH_RELATIONS)->get());

        $this->assertCount(2, $sections);
        $this->assertEqualsCanonicalizing([785.0, 500.0], array_map(fn (array $section): float => (float) $section['batch_size_kg'], $sections));
        $this->assertSame([2, 1], array_map(fn (array $section): int => (int) $section['batch_count'], $sections));
    }

    public function test_scheduled_send_emails_each_mo_run_separately(): void
    {
        $mail = Mockery::mock(SendOffice365MailOperation::class);
        $mail->shouldReceive('__invoke')->twice();
        $this->instance(SendOffice365MailOperation::class, $mail);

        $this->linkedDocument();
        $this->batchFor('30010010');
        $this->batchFor('30010010');
        ReportRecipient::create(['report_key' => 'doc_WM022', 'recipient_type' => 'direct', 'recipient_email' => 'qa@example.com', 'is_cc' => false, 'enabled' => true]);

        $log = app(SendReportOperation::class)('doc_WM022', now()->startOfDay(), now()->endOfDay(), 'scheduled');

        $this->assertSame(ReportSendLog::STATUS_SENT, $log->status);
        $this->assertSame(2, (int) $log->row_count);
        $this->assertSame(2, ReportSendLog::query()->where('report_key', 'like', 'doc_WM022@%')->where('status', ReportSendLog::STATUS_SENT)->count());
        $this->assertStringContainsString('qa@example.com', (string) ReportSendLog::query()->where('report_key', 'like', 'doc_WM022@%')->value('recipients_to'));
    }

    public function test_settings_links_a_document_to_a_recipe(): void
    {
        Role::findOrCreate('administrator');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('administrator');
        $this->actingAs($user);

        Volt::test('pages.settings.documents')
            ->call('createDocument')
            ->set('code', 'WM060')
            ->set('title', 'Dijon Batch Card')
            ->set('source_type', DocumentSources::TYPE_RECIPE)
            ->set('recipe_code', '30010010')
            ->assertSet('setup_orientation', 'landscape')
            ->assertSet('setup_columns.0.key', 'allergen_material')
            ->assertSet('recipe_preview.components.0.description', 'Spirit Vinegar 14%')
            ->call('saveDocumentMetadata')
            ->assertHasNoErrors();

        $document = DocumentReference::query()->where('code', 'WM060')->firstOrFail();
        $this->assertSame(DocumentSources::TYPE_RECIPE, $document->source_type);
        $this->assertSame('30010010', $document->recipe_code);
        $this->assertSame('WM060', RecipeCard::query()->where('recipe_code', '30010010')->value('document_reference'));
        $this->assertFalse((bool) ReportConfig::query()->where('report_key', 'doc_WM060')->value('enabled'));
    }
}
