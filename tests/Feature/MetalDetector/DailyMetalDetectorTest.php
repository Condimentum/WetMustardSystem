<?php

namespace Tests\Feature\MetalDetector;

use App\Features\MetalDetector\RecordMetalDetectorCheckFeature;
use App\Models\MetalDetectorCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyMetalDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_standalone_daily_metal_detector_check_can_be_recorded_without_a_batch(): void
    {
        $user = User::factory()->create();

        $check = app(RecordMetalDetectorCheckFeature::class)(null, [
            'check_type' => MetalDetectorCheck::TYPE_START,
            'fe10_pass' => true,
            'non_fe15_pass' => true,
            'ss20_pass' => true,
        ], $user);

        $this->assertNull($check->batch_record_id);
        $this->assertNull($check->manufacturing_order_id);
        $this->assertNull($check->product_id);
        $this->assertSame(MetalDetectorCheck::RESULT_PASS, $check->overall_result);
        $this->assertDatabaseHas('electronic_signatures', [
            'entity_name' => 'metal_detector_checks',
            'entity_id' => $check->id,
            'signature_purpose' => 'metal_detector_check',
        ]);
    }

    public function test_the_daily_metal_detector_register_page_is_available_to_authenticated_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('metal-detector.daily'))
            ->assertOk()
            ->assertSee('METAL DETECTION CHECK LOG');
    }
}
