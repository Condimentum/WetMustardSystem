<?php

namespace Tests\Feature\Batches;

use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\WinManMoComponentSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BatchAllocationOutstandingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{ManufacturingOrder, Product} */
    private function makeMo(int $winmanMo, float $planned): array
    {
        $product = Product::create(['recipe_code' => '30010001', 'product_name' => 'Test Mustard', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MO-OUT-'.$winmanMo, 'winman_manufacturing_order' => $winmanMo, 'winman_manufacturing_order_id' => 'MO-OUT-'.$winmanMo,
            'recipe_code' => '30010001', 'product_id' => $product->id, 'planned_quantity' => $planned,
            'quantity_outstanding' => $planned, 'winman_classification' => 30, 'winman_system_type' => 'F', 'status' => 'selected',
        ]);

        return [$order, $product];
    }

    private function addComponent(ManufacturingOrder $order, float $quantity, float $quantityIssued): void
    {
        WinManMoComponentSnapshot::create([
            'manufacturing_order_id' => $order->id,
            'winman_manufacturing_order' => $order->winman_manufacturing_order,
            'winman_work_in_progress' => 50001,
            'item_type' => 'C',
            'winman_component_product' => '100018',
            'winman_component_product_id' => '100018',
            'component_description' => 'Cracked Brown Mustard Seed',
            'classification' => '30',
            'quantity' => $quantity,
            'quantity_issued' => $quantityIssued,
            'quantity_outstanding' => max($quantity - $quantityIssued, 0),
            'snapshot_at' => now(),
        ]);
    }

    private function addBatch(ManufacturingOrder $order, Product $product, string $ref, float $planned, string $status): BatchRecord
    {
        return BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => $ref,
            'production_date' => now()->toDateString(), 'planned_quantity' => $planned, 'status' => $status,
        ]);
    }

    public function test_second_batch_outstanding_ignores_the_mo_wide_issued_quantity(): void
    {
        [$order, $product] = $this->makeMo(920001, planned: 1000);
        // The MO needs 300 kg of this component; batch 1 already issued the lot,
        // so the shared snapshot's quantity_issued is the MO-wide 300.
        $this->addComponent($order, quantity: 300, quantityIssued: 300);

        $this->addBatch($order, $product, 'MO-OUT-920001-100018-01', planned: 500, status: BatchRecord::STATUS_COMPLETED);
        $batch2 = $this->addBatch($order, $product, 'MO-OUT-920001-100018-02', planned: 500, status: BatchRecord::STATUS_IN_PROGRESS);

        $this->actingAs(User::factory()->create());

        // Per-batch requirement = 300 * (500 / 1000) = 150, nothing allocated to
        // batch 2 yet -> outstanding must be the full 150, not 0.
        Volt::test('pages.batches.show', ['batch' => $batch2->fresh()])
            ->assertOk()
            ->assertSeeInOrder(['Cracked Brown Mustard Seed', '150']);
    }

    public function test_single_batch_still_uses_snapshot_issued_as_allocated_fallback(): void
    {
        [$order, $product] = $this->makeMo(920002, planned: 1000);
        $this->addComponent($order, quantity: 300, quantityIssued: 90);

        $batch = $this->addBatch($order, $product, 'MO-OUT-920002-100018-01', planned: 500, status: BatchRecord::STATUS_IN_PROGRESS);

        $this->actingAs(User::factory()->create());

        // Required = 150, snapshot issued 90 counts as allocated on a single-batch
        // MO -> outstanding 60.
        Volt::test('pages.batches.show', ['batch' => $batch->fresh()])
            ->assertOk()
            ->assertSeeInOrder(['Cracked Brown Mustard Seed', '60']);
    }
}
