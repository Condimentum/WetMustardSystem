<?php

namespace Tests\Feature\Reporting;

use App\Domains\Reporting\ReportRegistry;
use App\Domains\Reporting\Reports\ProgramDocumentReport;
use App\Domains\Reporting\Reports\Wm002SaltMeterCalibrationReport;
use App\Domains\Reporting\Support\DocumentSources;
use App\Models\DocumentLayoutSetting;
use App\Models\DocumentReference;
use App\Models\MetalDetectorCheck;
use App\Models\ReportConfig;
use App\Models\SaltMeterCalibration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Role::findOrCreate('administrator');
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole('administrator');
        $this->actingAs($user);
    }

    public function test_existing_documents_infer_their_link_from_code_or_trigger_codes(): void
    {
        $sources = app(DocumentSources::class);

        $programDocument = DocumentReference::create(['code' => 'WM002', 'title' => 'Daily Salt Meter Calibration', 'status' => 'Active']);
        $triggerDocument = DocumentReference::create(['code' => 'WM012', 'title' => 'Buckets', 'trigger_material_codes' => ['90010012'], 'status' => 'Active']);
        $unlinkedDocument = DocumentReference::create(['code' => 'WM099', 'title' => 'Reference only', 'status' => 'Active']);

        $this->assertSame(['type' => DocumentSources::TYPE_PROGRAM, 'program_key' => 'wm002_salt_meter', 'recipe_code' => null], $sources->linkFor($programDocument));
        $this->assertSame(DocumentSources::TYPE_MATERIAL_TRIGGER, $sources->linkFor($triggerDocument)['type']);
        $this->assertNull($sources->linkFor($unlinkedDocument)['type']);
    }

    public function test_salt_meter_sheet_follows_the_linked_documents_column_setup(): void
    {
        $document = DocumentReference::create([
            'code' => 'QA-SALT',
            'title' => 'Salt Meter Check',
            'source_type' => DocumentSources::TYPE_PROGRAM,
            'program_key' => 'wm002_salt_meter',
            'status' => 'Active',
        ]);
        DocumentLayoutSetting::create([
            'document_reference_id' => $document->id,
            'settings' => [
                'columns' => [
                    ['key' => 'operator_name', 'label' => 'Who', 'width' => 40, 'visible' => true],
                    ['key' => 'reading', 'label' => 'Meter', 'width' => 60, 'visible' => true],
                    ['key' => 'passed', 'label' => 'Result', 'width' => 10, 'visible' => false],
                ],
            ],
        ]);

        SaltMeterCalibration::create([
            'checked_date' => now()->toDateString(),
            'reading' => 99.9,
            'passed' => true,
            'operator_name' => 'QA User',
        ]);

        $report = app(Wm002SaltMeterCalibrationReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertStringContainsString('Who', $report['html']);
        $this->assertStringContainsString('Meter', $report['html']);
        $this->assertStringNotContainsString('Result', $report['html']);
        $this->assertMatchesRegularExpression('/QA User.*99\.900/s', $report['html']);
        $this->assertStringStartsWith('qa-salt-', $report['attachments'][0]['name']);
        $this->assertFileExists($report['attachments'][0]['path']);
    }

    public function test_registry_ignores_trigger_codes_on_program_driven_documents(): void
    {
        DocumentReference::create([
            'code' => 'WM012',
            'title' => 'Buckets',
            'source_type' => DocumentSources::TYPE_PROGRAM,
            'program_key' => 'wm002_salt_meter',
            'trigger_material_codes' => ['90010012'],
            'status' => 'Active',
        ]);

        $this->assertFalse(app(ReportRegistry::class)->has('doc_WM012'));
    }

    public function test_settings_page_links_a_new_document_to_a_program_and_offers_its_columns(): void
    {
        $this->actingAsAdmin();

        $component = Volt::test('pages.settings.documents')
            ->call('createDocument')
            ->set('code', 'WM002')
            ->assertSet('source_type', DocumentSources::TYPE_PROGRAM)
            ->assertSet('program_key', 'wm002_salt_meter');

        $this->assertContains('reading', array_column($component->get('setup_columns'), 'key'));

        $component->set('title', 'Daily Salt Meter Calibration')
            ->call('saveDocumentMetadata')
            ->assertHasNoErrors();

        $document = DocumentReference::query()->where('code', 'WM002')->firstOrFail();
        $this->assertSame(DocumentSources::TYPE_PROGRAM, $document->source_type);
        $this->assertSame('wm002_salt_meter', $document->program_key);
        $this->assertNull($document->trigger_material_codes);
        $this->assertFalse(ReportConfig::query()->where('report_key', 'doc_WM002')->exists());
    }

    public function test_metal_detector_document_is_generated_from_discovered_table_fields(): void
    {
        $operator = User::factory()->create(['name' => 'Metal Operator']);

        DocumentReference::create([
            'code' => 'WM008',
            'title' => 'Metal Detector Verification',
            'source_type' => DocumentSources::TYPE_PROGRAM,
            'program_key' => 'metal_detector_daily',
            'status' => 'Active',
        ]);

        MetalDetectorCheck::create([
            'check_type' => MetalDetectorCheck::TYPE_START,
            'check_time' => now(),
            'fe10_pass' => true,
            'non_fe15_pass' => false,
            'ss20_pass' => true,
            'overall_result' => MetalDetectorCheck::RESULT_FAIL,
            'failure_action' => 'Stopped line',
            'signed_by' => $operator->id,
            'signed_at' => now(),
        ]);

        $report = app(ReportRegistry::class)->get('doc_WM008');
        $this->assertInstanceOf(ProgramDocumentReport::class, $report);

        $payload = $report->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $payload['row_count']);
        $this->assertStringContainsString('Ferrous 1.0', $payload['html']);
        $this->assertStringContainsString('Start Of Shift', $payload['html']);
        $this->assertStringContainsString('Metal Operator', $payload['html']);
        $this->assertMatchesRegularExpression('/Pass.*Fail.*Pass/s', $payload['html']);
        $this->assertStringNotContainsString('Stopped line', $payload['html']); // hidden by default
        $this->assertFileExists($payload['attachments'][0]['path']);
    }

    public function test_linking_a_document_to_a_table_backed_program_adds_a_disabled_scheduled_report(): void
    {
        $this->actingAsAdmin();

        Volt::test('pages.settings.documents')
            ->call('createDocument')
            ->set('code', 'WM008')
            ->set('title', 'Metal Detector Verification')
            ->set('source_type', DocumentSources::TYPE_PROGRAM)
            ->set('program_key', 'metal_detector_daily')
            ->assertSet('setup_columns.0.key', 'check_time')
            ->call('saveDocumentMetadata')
            ->assertHasNoErrors();

        $config = ReportConfig::query()->where('report_key', 'doc_WM008')->firstOrFail();
        $this->assertFalse((bool) $config->enabled);
        $this->assertTrue(app(ReportRegistry::class)->has('doc_WM008'));
    }

    public function test_settings_page_requires_trigger_codes_for_material_triggered_documents(): void
    {
        $this->actingAsAdmin();

        Volt::test('pages.settings.documents')
            ->call('createDocument')
            ->set('code', 'WM012')
            ->set('title', 'Buckets')
            ->set('source_type', DocumentSources::TYPE_MATERIAL_TRIGGER)
            ->call('saveDocumentMetadata')
            ->assertHasErrors(['trigger_material_codes' => 'required_if'])
            ->set('trigger_material_codes', '90010012')
            ->call('saveDocumentMetadata')
            ->assertHasNoErrors();

        $document = DocumentReference::query()->where('code', 'WM012')->firstOrFail();
        $this->assertSame(DocumentSources::TYPE_MATERIAL_TRIGGER, $document->source_type);
        $this->assertSame(['90010012'], $document->trigger_material_codes);
        $this->assertTrue(ReportConfig::query()->where('report_key', 'doc_WM012')->exists());
        $this->assertTrue(app(ReportRegistry::class)->has('doc_WM012'));
    }
}
