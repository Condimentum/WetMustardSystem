<?php

namespace App\Features\Pallecon;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Pallecon\Jobs\SealPalleconJob;
use App\Domains\Signature\Jobs\RecordElectronicSignatureJob;
use App\Models\Pallecon;
use App\Models\User;

/**
 * Seals a pallecon at scale-off, capturing the final-weight signature and an
 * audit entry. The sealed container's final weight is authoritative for labels.
 */
class SealPalleconFeature
{
    public function __construct(
        private readonly SealPalleconJob $sealPallecon,
        private readonly RecordElectronicSignatureJob $recordSignature,
        private readonly RecordAuditEntryJob $recordAuditEntry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(Pallecon $pallecon, array $attributes, User $user): Pallecon
    {
        $sealed = ($this->sealPallecon)($pallecon, $attributes, $user);

        ($this->recordSignature)($sealed, 'pallecon_sealed', $user, 'Pallecon sealed and final weight recorded');
        ($this->recordAuditEntry)($sealed, 'seal', $user, 'final_weight', null, (string) $sealed->final_weight);

        return $sealed;
    }
}
