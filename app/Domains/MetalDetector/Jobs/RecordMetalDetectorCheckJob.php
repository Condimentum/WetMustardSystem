<?php

namespace App\Domains\MetalDetector\Jobs;

use App\Models\BatchRecord;
use App\Models\MetalDetectorCheck;
use App\Models\User;
use Carbon\Carbon;

/**
 * Records a metal detector verification check. Batch context is optional; the
 * overall result is derived as a pass only when all three test pieces pass.
 */
class RecordMetalDetectorCheckJob
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(?BatchRecord $batch, array $attributes, User $user): MetalDetectorCheck
    {
        $fe = (bool) ($attributes['fe10_pass'] ?? false);
        $nonFe = (bool) ($attributes['non_fe15_pass'] ?? false);
        $ss = (bool) ($attributes['ss20_pass'] ?? false);

        $result = ($fe && $nonFe && $ss)
            ? MetalDetectorCheck::RESULT_PASS
            : MetalDetectorCheck::RESULT_FAIL;

        return MetalDetectorCheck::create([
            'batch_record_id' => $batch?->id,
            'manufacturing_order_id' => $batch?->manufacturing_order_id,
            'product_id' => $batch?->product_id,
            'check_type' => $attributes['check_type'],
            'check_time' => isset($attributes['check_time'])
                ? Carbon::parse((string) $attributes['check_time'])
                : now(),
            'fe10_pass' => $fe,
            'non_fe15_pass' => $nonFe,
            'ss20_pass' => $ss,
            'overall_result' => $result,
            'bin_locked' => $attributes['bin_locked'] ?? null,
            'bin_empty' => $attributes['bin_empty'] ?? null,
            'is_recheck' => (bool) ($attributes['is_recheck'] ?? false),
            'failure_action' => $attributes['failure_action'] ?? null,
            'comments' => $attributes['comments'] ?? null,
            'signed_by' => $user->id,
            'signed_at' => now(),
        ]);
    }
}
