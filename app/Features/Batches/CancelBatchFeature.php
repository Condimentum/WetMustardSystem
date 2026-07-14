<?php

namespace App\Features\Batches;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Batch\Jobs\CancelBatchJob;
use App\Domains\Signature\Jobs\RecordElectronicSignatureJob;
use App\Models\BatchRecord;
use App\Models\User;

/**
 * Cancels a batch and records signature/audit trail entries.
 */
class CancelBatchFeature
{
    public function __construct(
        private readonly CancelBatchJob $cancelBatch,
        private readonly RecordElectronicSignatureJob $recordSignature,
        private readonly RecordAuditEntryJob $recordAuditEntry,
    ) {
    }

    public function __invoke(BatchRecord $batch, User $user, ?string $reason = null): BatchRecord
    {
        if ($batch->status !== BatchRecord::STATUS_IN_PROGRESS) {
            return $batch;
        }

        $previousStatus = $batch->status;
        $batch = ($this->cancelBatch)($batch, $user);

        ($this->recordSignature)($batch, 'batch_cancelled', $user, 'Batch cancelled');
        ($this->recordAuditEntry)(
            $batch,
            'cancel',
            $user,
            'status',
            $previousStatus,
            $batch->status,
            $reason ?? 'Batch cancelled by operator',
        );

        return $batch;
    }
}
