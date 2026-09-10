<?php

namespace App\Features\Pallecon;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Pallecon\Jobs\OpenPalleconJob;
use App\Models\Pallecon;
use App\Models\User;

/**
 * Opens a new pallecon container and records the opening in the audit trail.
 */
class OpenPalleconFeature
{
    public function __construct(
        private readonly OpenPalleconJob $openPallecon,
        private readonly RecordAuditEntryJob $recordAuditEntry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes, User $user): Pallecon
    {
        $pallecon = ($this->openPallecon)($attributes);

        ($this->recordAuditEntry)($pallecon, 'open', $user, 'serial_number', null, $pallecon->serial_number);

        return $pallecon;
    }
}
