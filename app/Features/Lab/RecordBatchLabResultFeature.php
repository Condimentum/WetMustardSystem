<?php

namespace App\Features\Lab;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Lab\Jobs\PlaceBatchOnHoldJob;
use App\Domains\Lab\Jobs\RecordLabResultJob;
use App\Domains\Signature\Jobs\RecordElectronicSignatureJob;
use App\Models\BatchLabResult;
use App\Models\BatchRecord;
use App\Models\User;
use App\Operations\RaiseNotificationOperation;

/**
 * Records a QA lab pass/fail against a batch. A fail is signed, audited, and
 * automatically propagates a hold to the batch and any pallecons it filled,
 * then raises a real-time alert to QA (scope §8).
 */
class RecordBatchLabResultFeature
{
    public function __construct(
        private readonly RecordLabResultJob $recordLabResult,
        private readonly PlaceBatchOnHoldJob $placeBatchOnHold,
        private readonly RecordElectronicSignatureJob $recordSignature,
        private readonly RecordAuditEntryJob $recordAuditEntry,
        private readonly RaiseNotificationOperation $raiseNotification,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(BatchRecord $batch, array $attributes, User $user): BatchLabResult
    {
        $result = ($this->recordLabResult)($batch, $attributes, $user);

        ($this->recordSignature)(
            $result,
            'lab_result',
            $user,
            "Lab result for batch {$batch->batch_number}: {$result->result}",
            $result->comment,
        );

        if ($result->result === BatchLabResult::RESULT_FAIL) {
            $reason = 'Lab failure'.($result->comment ? ': '.$result->comment : '');
            $held = ($this->placeBatchOnHold)($batch, $reason);

            ($this->recordAuditEntry)($batch, 'lab_fail_hold', $user, 'held_at', null, $reason);

            $serials = $held->pluck('serial_number')->filter()->implode(', ');
            $message = "Batch {$batch->batch_number} FAILED lab testing and is on hold."
                .($serials !== '' ? " Pallecons quarantined: {$serials}." : '');

            ($this->raiseNotification)('lab_hold', $batch, $message, 'critical');
        } else {
            ($this->recordAuditEntry)($batch, 'lab_result', $user, 'result', null, $result->result);
        }

        return $result;
    }
}
