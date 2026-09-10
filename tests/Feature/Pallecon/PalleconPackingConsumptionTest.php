<?php

namespace Tests\Feature\Pallecon;

use App\Features\Packing\ConsumePalleconFeature;
use App\Features\Packing\CreatePackingRunFeature;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\OpenPalleconFeature;
use App\Features\Pallecon\SealPalleconFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PalleconPackingConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeBatch(string $batchNumber): BatchRecord
    {
        $this->seq++;
        $product = Product::create(['recipe_code' => 'RP'.$this->seq, 'product_name' => 'Test', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MOP'.$this->seq, 'winman_manufacturing_order' => 8000 + $this->seq,
            'winman_manufacturing_order_id' => 'MOP'.$this->seq, 'recipe_code' => 'RP'.$this->seq,
            'product_id' => $product->id, 'planned_quantity' => 500, 'quantity_outstanding' => 500,
            'winman_system_type' => 'F', 'status' => 'selected',
        ]);

        return BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => $batchNumber,
            'production_date' => now()->toDateString(), 'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_consuming_a_shared_container_records_all_contributing_batches_and_marks_it_consumed(): void
    {
        $user = User::factory()->create();
        $batch1 = $this->makeBatch('WM-PC-01');
        $batch2 = $this->makeBatch('WM-PC-02');

        $pallecon = app(OpenPalleconFeature::class)(['serial_number' => 'PAL-PC-1'], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch1, ['fill_weight' => 400], $user);
        app(AttachBatchFillFeature::class)($pallecon, $batch2, ['fill_weight' => 300], $user);
        app(SealPalleconFeature::class)($pallecon, ['final_weight' => 690], $user);

        $run = app(CreatePackingRunFeature::class)($batch1, 'Day', $user);
        $ibc = app(ConsumePalleconFeature::class)($run, ['pallecon_id' => $pallecon->id, 'time_on' => now()], $user);

        $this->assertStringContainsString('WM-PC-01', (string) $ibc->source_batch_number);
        $this->assertStringContainsString('WM-PC-02', (string) $ibc->source_batch_number);
        $this->assertSame($pallecon->id, $ibc->pallecon_id);
        $this->assertSame(Pallecon::STATUS_CONSUMED, $pallecon->fresh()->status);
    }
}
