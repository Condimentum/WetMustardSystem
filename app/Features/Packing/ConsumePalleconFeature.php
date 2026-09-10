<?php

namespace App\Features\Packing;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Domains\Packing\Jobs\AddPackingRunIbcJob;
use App\Models\Pallecon;
use App\Models\PackingRun;
use App\Models\PackingRunIbc;
use App\Models\User;

/**
 * Records consumption of a pallecon/IBC by a packing run (traceability link) and
 * audits it.
 *
 * When a first-class pallecon container is consumed, the source batch and MO
 * numbers are derived from ALL of its fills (a container may hold several
 * batches) rather than a single operator-entered value, and the container is
 * marked consumed.
 */
class ConsumePalleconFeature
{
    public function __construct(
        private readonly AddPackingRunIbcJob $addPackingRunIbc,
        private readonly RecordAuditEntryJob $recordAuditEntry,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(PackingRun $run, array $attributes, ?User $user = null): PackingRunIbc
    {
        $container = null;

        if (! empty($attributes['pallecon_id'])) {
            $container = Pallecon::with('fills.batchRecord.manufacturingOrder')->find($attributes['pallecon_id']);
        }

        if ($container !== null) {
            $batchNumbers = $container->fills
                ->map(fn ($fill) => $fill->batchRecord?->batch_number)
                ->filter()
                ->unique()
                ->values();

            $moNumbers = $container->fills
                ->map(fn ($fill) => $fill->batchRecord?->manufacturingOrder?->mo_number)
                ->filter()
                ->unique()
                ->values();

            $attributes['source_batch_number'] = $batchNumbers->isNotEmpty()
                ? $batchNumbers->implode(', ')
                : ($attributes['source_batch_number'] ?? null);
            $attributes['source_mo_number'] = $moNumbers->isNotEmpty()
                ? $moNumbers->implode(', ')
                : ($attributes['source_mo_number'] ?? null);
        }

        $ibc = ($this->addPackingRunIbc)($run, $attributes);

        if ($container !== null && $container->status === Pallecon::STATUS_SEALED) {
            $container->update(['status' => Pallecon::STATUS_CONSUMED]);
        }

        ($this->recordAuditEntry)($ibc, 'create', $user, 'source_batch_number', null, $ibc->source_batch_number);

        return $ibc;
    }
}
