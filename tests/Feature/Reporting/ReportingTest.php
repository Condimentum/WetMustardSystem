<?php

namespace Tests\Feature\Reporting;

use App\Domains\Reporting\Jobs\ResolveReportRecipientsJob;
use App\Domains\Reporting\Reports\DailyIntermediateProductionReport;
use App\Domains\Reporting\Reports\OpenBatchesReport;
use App\Features\Reporting\SendReportNowFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\PalleconRecord;
use App\Models\Product;
use App\Models\ReportRecipient;
use App\Models\ReportSendLog;
use App\Models\User;
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
