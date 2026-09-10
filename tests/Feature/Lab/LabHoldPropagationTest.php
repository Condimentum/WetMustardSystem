<?php

namespace Tests\Feature\Lab;

use App\Features\Lab\RecordBatchLabResultFeature;
use App\Features\Lab\ReleaseBatchHoldFeature;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\OpenPalleconFeature;
use App\Features\Pallecon\SealPalleconFeature;
use App\Models\BatchLabResult;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabHoldPropagationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeBatch(string $batchNumber): BatchRecord
    {
        $this->seq++;
        $product = Product::create(['recipe_code' => 'RL'.$this->seq, 'product_name' => 'Test', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MOL'.$this->seq, 'winman_manufacturing_order' => 9000 + $this->seq,
            'winman_manufacturing_order_id' => 'MOL'.$this->seq, 'recipe_code' => 'RL'.$this->seq,
            'product_id' => $product->id, 'planned_quantity' => 500, 'quantity_outstanding' => 500,
            'winman_system_type' => 'F', 'status' => 'selected',
        ]);

        return BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => $batchNumber,
            'production_date' => now()->toDateString(), 'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_a_failed_batch_holds_itself_and_its_shared_pallecon_but_not_the_other_passing_batch(): void
    {
        $user = User::factory()->create();
        $batch1 = $this->makeBatch('WM-LH-01');
        $batch2 = $this->makeBatch('WM-LH-02');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-LH-1'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch1, ['fill_weight' => 400], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch2, ['fill_weight' => 300], $user);
        app(SealPalleconFeature::class)($pallecon, ['final_weight' => 690], $user);

        app(RecordBatchLabResultFeature::class)($batch1, ['result' => BatchLabResult::RESULT_PASS], $user);
        app(RecordBatchLabResultFeature::class)($batch2, ['result' => BatchLabResult::RESULT_FAIL, 'comment' => 'High salt'], $user);

        $this->assertTrue($batch2->fresh()->isOnHold());
        $this->assertFalse($batch1->fresh()->isOnHold());
        $this->assertTrue($pallecon->fresh()->isOnHold());
        $this->assertStringContainsString('WM-LH-02', (string) $pallecon->fresh()->hold_reason);

        $this->assertDatabaseHas('electronic_signatures', ['entity_name' => 'batch_lab_results', 'signature_purpose' => 'lab_result']);
        $this->assertDatabaseHas('audit_trails', ['entity_name' => 'batch_records', 'action' => 'lab_fail_hold']);
    }

    public function test_passing_result_does_not_place_a_hold(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM-LH-03');

        app(RecordBatchLabResultFeature::class)($batch, ['result' => BatchLabResult::RESULT_PASS], $user);

        $this->assertFalse($batch->fresh()->isOnHold());
    }

    public function test_releasing_the_hold_clears_the_batch_and_pallecon(): void
    {
        $user = User::factory()->create();
        $batch = $this->makeBatch('WM-LH-04');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-LH-2'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch, ['fill_weight' => 400], $user);
        app(SealPalleconFeature::class)($pallecon, ['final_weight' => 400], $user);

        app(RecordBatchLabResultFeature::class)($batch, ['result' => BatchLabResult::RESULT_FAIL], $user);
        $this->assertTrue($pallecon->fresh()->isOnHold());

        app(ReleaseBatchHoldFeature::class)($batch->fresh(), 'Retested and cleared', $user);

        $this->assertFalse($batch->fresh()->isOnHold());
        $this->assertFalse($pallecon->fresh()->isOnHold());
        $this->assertDatabaseHas('audit_trails', ['entity_name' => 'batch_records', 'action' => 'hold_release']);
    }

    public function test_release_keeps_container_held_while_another_failed_batch_remains(): void
    {
        $user = User::factory()->create();
        $batch1 = $this->makeBatch('WM-LH-05');
        $batch2 = $this->makeBatch('WM-LH-06');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-LH-3'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch1, ['fill_weight' => 400], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch2, ['fill_weight' => 300], $user);
        app(SealPalleconFeature::class)($pallecon, ['final_weight' => 690], $user);

        app(RecordBatchLabResultFeature::class)($batch1, ['result' => BatchLabResult::RESULT_FAIL], $user);
        app(RecordBatchLabResultFeature::class)($batch2, ['result' => BatchLabResult::RESULT_FAIL], $user);
        $this->assertTrue($pallecon->fresh()->isOnHold());

        app(ReleaseBatchHoldFeature::class)($batch1->fresh(), 'Batch 1 retested OK', $user);

        // Container stays held because batch 2 is still on hold.
        $this->assertTrue($pallecon->fresh()->isOnHold());
        $this->assertFalse($batch1->fresh()->isOnHold());
        $this->assertTrue($batch2->fresh()->isOnHold());
    }
}
