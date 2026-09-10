<?php

namespace App\Features\Waste;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Signature\Jobs\RecordElectronicSignatureJob;
use App\Domains\Waste\Jobs\RecordWasteJob;
use App\Models\User;
use App\Models\WasteRecord;

/**
 * Records a waste / scrap entry with an electronic signature and audit trail.
 */
class RecordWasteFeature
{
    public function __construct(
        private readonly RecordWasteJob $recordWaste,
        private readonly RecordElectronicSignatureJob $recordSignature,
        private readonly RecordAuditEntryJob $recordAuditEntry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes, User $user): WasteRecord
    {
        $waste = ($this->recordWaste)($attributes, $user);

        ($this->recordSignature)(
            $waste,
            'waste_recorded',
            $user,
            "Waste recorded: {$waste->quantity} {$waste->uom} ({$waste->categoryLabel()})",
            $waste->reason,
        );

        ($this->recordAuditEntry)($waste, 'create', $user, 'category', null, $waste->category);

        return $waste;
    }
}
