<?php

namespace App\Features\Pallecon;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Pallecon\Jobs\AttachBatchFillJob;
use App\Domains\Signature\Jobs\RecordElectronicSignatureJob;
use App\Models\BatchRecord;
use App\Models\Pallecon;
use App\Models\PalleconFill;
use App\Models\User;

/**
 * Attaches a batch fill to a pallecon, capturing a signed contribution and an
 * audit entry linking the batch to the container.
 */
class AttachBatchFillFeature
{
    public function __construct(
        private readonly AttachBatchFillJob $attachBatchFill,
        private readonly RecordElectronicSignatureJob $recordSignature,
        private readonly RecordAuditEntryJob $recordAuditEntry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(
        Pallecon $pallecon,
        BatchRecord $batch,
        array $attributes,
        User $user,
    ): PalleconFill {
        $fill = ($this->attachBatchFill)($pallecon, $batch, $attributes, $user);

        ($this->recordSignature)($fill, 'pallecon_fill', $user, 'Batch filled into pallecon');
        ($this->recordAuditEntry)(
            $pallecon,
            'fill',
            $user,
            'batch_record_id',
            null,
            $batch->batch_number,
        );

        return $fill;
    }
}
