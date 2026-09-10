<?php

namespace Tests\Feature\Waste;

use App\Domains\Waste\Exceptions\WasteException;
use App\Features\Waste\RecordWasteFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\WasteRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WasteRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_waste_stores_signature_and_audit(): void
    {
        $user = User::factory()->create();

        $waste = app(RecordWasteFeature::class)([
            'category' => WasteRecord::CATEGORY_SPILLAGE,
            'quantity' => 12.5,
            'uom' => 'kg',
            'reason' => 'Tub knocked over',
        ], $user);

        $this->assertDatabaseHas('waste_records', ['id' => $waste->id, 'category' => 'spillage', 'quantity' => 12.5]);
        $this->assertDatabaseHas('electronic_signatures', ['entity_name' => 'waste_records', 'signature_purpose' => 'waste_recorded']);
        $this->assertDatabaseHas('audit_trails', ['entity_name' => 'waste_records', 'action' => 'create']);
    }

    public function test_category_reason_and_quantity_are_required(): void
    {
        $user = User::factory()->create();

        $this->expectException(WasteException::class);
        app(RecordWasteFeature::class)(['category' => 'spillage', 'quantity' => 5, 'reason' => ''], $user);
    }

    public function test_invalid_category_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(WasteException::class);
        app(RecordWasteFeature::class)(['category' => 'nonsense', 'quantity' => 5, 'reason' => 'x'], $user);
    }

    public function test_screen_records_waste_linked_to_a_batch(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $product = Product::create(['recipe_code' => 'RW', 'product_name' => 'Test', 'active_flag' => true]);
        $order = ManufacturingOrder::create([
            'mo_number' => 'MOW', 'winman_manufacturing_order' => 4242, 'winman_manufacturing_order_id' => 'MOW',
            'recipe_code' => 'RW', 'product_id' => $product->id, 'planned_quantity' => 500,
            'quantity_outstanding' => 500, 'winman_system_type' => 'F', 'status' => 'selected',
        ]);
        $batch = BatchRecord::create([
            'manufacturing_order_id' => $order->id, 'product_id' => $product->id, 'batch_number' => 'WM-W-01',
            'production_date' => now()->toDateString(), 'status' => BatchRecord::STATUS_IN_PROGRESS,
        ]);

        Volt::test('pages.waste.index')
            ->set('form.category', 'process_loss')
            ->set('form.quantity', '3.2')
            ->set('form.reason', 'Line purge')
            ->set('form.batch_number', 'WM-W-01')
            ->call('record')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('waste_records', [
            'batch_record_id' => $batch->id,
            'category' => 'process_loss',
            'quantity' => 3.2,
        ]);
    }

    public function test_screen_rejects_unknown_batch_number(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.waste.index')
            ->set('form.category', 'spillage')
            ->set('form.quantity', '1')
            ->set('form.reason', 'x')
            ->set('form.batch_number', 'NOPE-999')
            ->call('record')
            ->assertHasErrors('form.batch_number');

        $this->assertDatabaseCount('waste_records', 0);
    }
}
