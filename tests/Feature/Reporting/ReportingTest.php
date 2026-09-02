<?php

namespace Tests\Feature\Reporting;

use App\Domains\Reporting\Jobs\ResolveReportRecipientsJob;
use App\Domains\Reporting\Reports\DailyIntermediateProductionReport;
use App\Domains\Reporting\Reports\MetalDetectorVerificationSheetReport;
use App\Domains\Reporting\Reports\OpenBatchesReport;
use App\Domains\Reporting\Reports\Wm005WetMustardLabTestingReport;
use App\Domains\Reporting\Reports\Wm010RinseWaterTestReport;
use App\Domains\Reporting\Reports\Wm001LabScalesCalibrationReport;
use App\Domains\Reporting\Reports\Wm002SaltMeterCalibrationReport;
use App\Domains\Reporting\Reports\Wm006ViscosityAutozeroReport;
use App\Domains\Reporting\Reports\Wm013ProductionScalesCalibrationReport;
use App\Features\Reporting\SendReportNowFeature;
use App\Models\BatchRecord;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\LabScaleCalibration;
use App\Models\ManufacturingOrder;
use App\Models\MetalDetectorCheck;
use App\Models\PalleconRecord;
use App\Models\ProductionScaleCalibration;
use App\Models\Product;
use App\Models\ReportRecipient;
use App\Models\ReportSendLog;
use App\Models\SaltMeterCalibration;
use App\Models\User;
use App\Models\ViscosityMeterAutozeroCheck;
use App\Models\Wm005LabTestingEntry;
use App\Models\Wm010RinseWaterTestEntry;
use App\Operations\SendOffice365MailOperation;
use App\Operations\SendReportOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMailer(bool $expectSend = true): void
    {
        $mail = Mockery::mock(SendOffice365MailOperation::class);
        if ($expectSend) {
            $mail->shouldReceive('__invoke')->once();
        } else {
            $mail->shouldReceive('__invoke')->never();
        }
        $this->instance(SendOffice365MailOperation::class, $mail);
    }

    public function test_open_batches_report_generates_html_with_row_count(): void
    {
        $product = Product::create(['recipe_code' => 'R1', 'product_name' => 'Test', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MO1', 'winman_manufacturing_order' => 1, 'winman_manufacturing_order_id' => 'MO1',
            'recipe_code' => 'R1', 'product_id' => $product->id, 'planned_quantity' => 1, 'quantity_outstanding' => 1,
            'winman_system_type' => 'F', 'status' => 'selected',
        ]);
        BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => 'WM-1',
            'production_date' => now()->toDateString(), 'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);

        $report = app(OpenBatchesReport::class)->generate(now()->subDay(), now());

        $this->assertSame(1, $report['row_count']);
        $this->assertStringContainsString('WM-1', $report['html']);
    }

    public function test_daily_intermediate_production_report_lists_pallecon_batches(): void
    {
        $product = Product::create([
            'recipe_code' => 'R-INTERMEDIATE',
            'product_name' => 'Intermediate Mustard',
            'active_flag' => true,
        ]);

        $palleconOrder = ManufacturingOrder::create([
            'mo_number' => 'MO-PALLECON-1',
            'winman_manufacturing_order' => 1001,
            'winman_manufacturing_order_id' => 'MO-PALLECON-1',
            'winman_product_id' => '50010001',
            'recipe_code' => 'R-INTERMEDIATE',
            'product_id' => $product->id,
            'planned_quantity' => 1200,
            'quantity_outstanding' => 1200,
            'winman_classification' => 30,
            'winman_unit_of_measure' => 2,
            'winman_unit_of_measure_description' => 'Pallecon',
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);

        $otherOrder = ManufacturingOrder::create([
            'mo_number' => 'MO-NON-PALLECON-1',
            'winman_manufacturing_order' => 1002,
            'winman_manufacturing_order_id' => 'MO-NON-PALLECON-1',
            'winman_product_id' => '70010001',
            'recipe_code' => 'R-INTERMEDIATE',
            'product_id' => $product->id,
            'planned_quantity' => 500,
            'quantity_outstanding' => 500,
            'winman_classification' => 29,
            'winman_unit_of_measure' => 1,
            'winman_unit_of_measure_description' => 'Kilogram',
            'winman_system_type' => 'F',
            'status' => 'selected',
        ]);

        $includedBatch = BatchRecord::create([
            'manufacturing_order_id' => $palleconOrder->id,
            'product_id' => $product->id,
            'batch_number' => 'WM-PAL-001',
            'production_date' => now()->toDateString(),
            'planned_quantity' => 1200,
            'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);

        BatchRecord::create([
            'manufacturing_order_id' => $otherOrder->id,
            'product_id' => $product->id,
            'batch_number' => 'WM-OTHER-001',
            'production_date' => now()->toDateString(),
            'planned_quantity' => 500,
            'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);

        PalleconRecord::create([
            'batch_record_id' => $includedBatch->id,
            'mo_number' => 'MO-PALLECON-1',
            'ticket_number' => 'T-001',
            'serial_number' => 'PAL-001',
            'fill_weight' => 510.25,
        ]);

        PalleconRecord::create([
            'batch_record_id' => $includedBatch->id,
            'mo_number' => 'MO-PALLECON-1',
            'ticket_number' => 'T-002',
            'serial_number' => 'PAL-002',
            'fill_weight' => 489.75,
        ]);

        $report = app(DailyIntermediateProductionReport::class)->generate(now()->subDay(), now());

        $this->assertSame(1, $report['row_count']);
        $this->assertStringContainsString('WM-PAL-001', $report['html']);
        $this->assertStringContainsString('Pallecon', $report['html']);
        $this->assertStringContainsString('1000.000', $report['html']);
        $this->assertStringNotContainsString('WM-OTHER-001', $report['html']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertIsArray($report['attachments']);
        $this->assertNotEmpty($report['attachments']);
        $this->assertArrayHasKey('path', $report['attachments'][0]);
        $this->assertFileExists($report['attachments'][0]['path']);
            $this->assertTrue(str_ends_with(strtolower((string) $report['attachments'][0]['name']), '.pdf'));
            $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
    }

    public function test_send_report_now_sends_and_logs_when_recipients_exist(): void
    {
        $this->fakeMailer();
        ReportRecipient::create(['report_key' => null, 'recipient_type' => 'direct', 'recipient_email' => 'qa@example.com', 'is_cc' => false, 'enabled' => true]);

        $log = app(SendReportNowFeature::class)(OpenBatchesReport::KEY, now()->subDay(), now(), null);

        $this->assertSame(ReportSendLog::STATUS_SENT, $log->status);
        $this->assertStringContainsString('qa@example.com', $log->recipients_to);
    }

    public function test_send_is_skipped_when_no_recipients(): void
    {
        $this->fakeMailer(expectSend: false);

        $log = app(SendReportOperation::class)(OpenBatchesReport::KEY, now()->subDay(), now(), 'manual');

        $this->assertSame(ReportSendLog::STATUS_SKIPPED, $log->status);
    }

    public function test_unknown_report_key_is_logged_as_failed(): void
    {
        $this->fakeMailer(expectSend: false);

        $log = app(SendReportOperation::class)('not_a_real_report', now()->subDay(), now(), 'manual');

        $this->assertSame(ReportSendLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('not registered', (string) $log->error_message);
    }

    public function test_role_recipients_resolve_to_user_emails(): void
    {
        Role::create(['name' => 'qa_technical', 'guard_name' => 'web']);
        $user = User::factory()->create(['email' => 'tech@example.com']);
        $user->assignRole('qa_technical');

        ReportRecipient::create(['report_key' => null, 'recipient_type' => 'role', 'role_key' => 'qa_technical', 'is_cc' => false, 'enabled' => true]);

        $resolved = app(ResolveReportRecipientsJob::class)(OpenBatchesReport::KEY);

        $this->assertContains('tech@example.com', $resolved['to']);
    }

    public function test_wm001_report_generates_pdf_attachment(): void
    {
        $this->seedCalibrationDocument('WM001', 'WM001 Lab Scales Daily Calibration');

        LabScaleCalibration::create([
            'checked_date' => now()->toDateString(),
            'reading' => 100.001,
            'passed' => true,
            'operator_name' => 'QA User',
        ]);

        $report = app(Wm001LabScalesCalibrationReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertFileExists($report['attachments'][0]['path']);
        $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
        $this->assertStringContainsString('WM001 Lab Scales Daily Calibration', $report['html']);
    }

    public function test_wm002_report_generates_pdf_attachment(): void
    {
        $this->seedCalibrationDocument('WM002', 'WM002 Daily Salt Meter Calibration');

        SaltMeterCalibration::create([
            'checked_date' => now()->toDateString(),
            'reading' => 99.900,
            'passed' => true,
            'operator_name' => 'QA User',
        ]);

        $report = app(Wm002SaltMeterCalibrationReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertFileExists($report['attachments'][0]['path']);
        $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
        $this->assertStringContainsString('WM002 Daily Salt Meter Calibration', $report['html']);
    }

    public function test_wm006_report_generates_pdf_attachment(): void
    {
        $this->seedCalibrationDocument('WM006', 'WM006 Viscosity Meter Autozero Check Complete');

        ViscosityMeterAutozeroCheck::create([
            'checked_date' => now()->toDateString(),
            'complete' => true,
            'operator_name' => 'QA User',
        ]);

        $report = app(Wm006ViscosityAutozeroReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertFileExists($report['attachments'][0]['path']);
        $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
        $this->assertStringContainsString('WM006 Viscosity Meter Autozero Check', $report['html']);
    }

    public function test_wm013_report_generates_pdf_attachment(): void
    {
        $this->seedCalibrationDocument('WM013', 'WM013 Production Scales Daily Calibration');

        ProductionScaleCalibration::create([
            'checked_date' => now()->toDateString(),
            'powder_3kg_reading' => 100.000,
            'powder_30kg_reading' => 10.000,
            'pallecon_scale_reading' => 10.000,
            'bucket_filler_scale_reading' => 10.000,
            'passed' => true,
            'operator_name' => 'QA User',
        ]);

        $report = app(Wm013ProductionScalesCalibrationReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertFileExists($report['attachments'][0]['path']);
        $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
        $this->assertStringContainsString('WM013 Production Scales Daily Calibration', $report['html']);
    }

    public function test_metal_detector_verification_report_generates_pdf_attachment(): void
    {
        $this->seedMetalDetectorDocument();

        $operator = User::factory()->create(['name' => 'QA User']);

        MetalDetectorCheck::create([
            'batch_record_id' => null,
            'manufacturing_order_id' => null,
            'product_id' => null,
            'check_time' => now(),
            'check_type' => MetalDetectorCheck::TYPE_HOURLY,
            'fe10_pass' => true,
            'non_fe15_pass' => true,
            'ss20_pass' => true,
            'bin_locked' => true,
            'bin_empty' => true,
            'overall_result' => MetalDetectorCheck::RESULT_PASS,
            'is_recheck' => false,
            'signed_by' => $operator->id,
            'signed_at' => now(),
        ]);

        $report = app(MetalDetectorVerificationSheetReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertFileExists($report['attachments'][0]['path']);
        $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
        $this->assertStringContainsString('Metal Detector Verification Sheet', $report['subject']);
    }

    public function test_wm005_report_generates_pdf_attachment(): void
    {
        $this->seedCalibrationDocument('WM005', 'WM005 Wet mustard lab testing');

        Wm005LabTestingEntry::create([
            'tested_date' => now()->toDateString(),
            'batch_number' => 'WM-BATCH-01',
            'ph' => 3.500,
            'salt' => 1.200,
            'tested_by' => 'QA Tester',
        ]);

        $report = app(Wm005WetMustardLabTestingReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertFileExists($report['attachments'][0]['path']);
        $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
    }

    public function test_wm010_report_generates_pdf_attachment(): void
    {
        $this->seedCalibrationDocument('WM010', 'WM010 Rinse water test sheet - chemical & sulphite');

        Wm010RinseWaterTestEntry::create([
            'tested_date' => now()->toDateString(),
            'section' => Wm010RinseWaterTestEntry::SECTION_CLEANING_CHEMICALS,
            'equipment' => 'Tank 1',
            'reading' => 7.000,
            'reading_unit' => 'pH',
            'pass_or_fail' => 'Pass',
            'operator_name' => 'Operator B',
        ]);

        $report = app(Wm010RinseWaterTestReport::class)->generate(now()->startOfDay(), now()->endOfDay());

        $this->assertSame(1, $report['row_count']);
        $this->assertArrayHasKey('attachments', $report);
        $this->assertFileExists($report['attachments'][0]['path']);
        $this->assertGreaterThan(0, filesize($report['attachments'][0]['path']));
    }

    private function seedCalibrationDocument(string $code, string $title): void
    {
        $document = DocumentReference::create([
            'code' => $code,
            'title' => $title,
            'version' => 'V1',
            'issue_date' => now()->toDateString(),
            'module' => 'Calibration',
            'status' => 'active',
        ]);

        DocumentReferenceChange::create([
            'document_reference_id' => $document->id,
            'issue_version' => 'V1',
            'date_issued' => now()->toDateString(),
            'issued_by' => 'QA',
            'reason_for_change' => 'Initial issue',
        ]);
    }

    private function seedMetalDetectorDocument(): void
    {
        $document = DocumentReference::create([
            'code' => 'WM017',
            'title' => 'Metal detector verification sheet',
            'version' => 'V1',
            'issue_date' => now()->toDateString(),
            'module' => 'Metal Detector',
            'status' => 'active',
        ]);

        DocumentReferenceChange::create([
            'document_reference_id' => $document->id,
            'issue_version' => 'V1',
            'date_issued' => now()->toDateString(),
            'issued_by' => 'QA',
            'reason_for_change' => 'Initial issue',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
