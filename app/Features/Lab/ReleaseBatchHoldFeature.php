<?php

namespace App\Features\Lab;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Lab\Exceptions\LabException;
use App\Domains\Lab\Jobs\ReleaseBatchHoldJob;
use App\Domains\Signature\Jobs\RecordElectronicSignatureJob;
use App\Models\BatchRecord;
use App\Models\User;

/**
 * Releases a QA hold on a batch (and any pallecons no longer held by another
 * failed batch), recording a signed, audited reason.
 */
class ReleaseBatchHoldFeature
{
    public function __construct(
        private readonly ReleaseBatchHoldJob $releaseBatchHold,
        private readonly RecordElectronicSignatureJob $recordSignature,
        private readonly RecordAuditEntryJob $recordAuditEntry,
    ) {
    }

    public function __invoke(BatchRecord $batch, string $reason, User $user): BatchRecord
    {
        if (! $batch->isOnHold()) {
            throw new LabException('Batch is not on hold.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new LabException('A reason is required to release a hold.');
        }

        ($this->releaseBatchHold)($batch, $reason);

        ($this->recordSignature)($batch, 'hold_release', $user, "Hold released for batch {$batch->batch_number}", $reason);
        ($this->recordAuditEntry)($batch, 'hold_release', $user, 'held_at', 'held', $reason);

        return $batch->refresh();
    }
}
