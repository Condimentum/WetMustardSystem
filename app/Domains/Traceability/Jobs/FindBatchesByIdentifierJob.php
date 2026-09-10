<?php

namespace App\Domains\Traceability\Jobs;

use App\Models\BatchRecord;
use App\Models\DrumProcessingPallet;
use App\Models\DrumRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconRecord;
use App\Models\PalletRecord;

/**
 * Backward trace resolver: maps any finished/output or upstream identifier
 * (finished pallet, drum, pallet ticket, pallecon serial, batch number or MO
 * reference) to the batch record(s) it belongs to, with match context.
 *
 * @return array<int, array{batch: BatchRecord, matches: array<int, array{on: string, value: ?string}>}>
 */
class FindBatchesByIdentifierJob
{
    public function __invoke(string $term): array
    {
        $like = '%'.trim($term).'%';

        /** @var array<int, array{matches: array<int, array{on: string, value: ?string}>}> $found */
        $found = [];
        $register = function (?int $batchId, string $on, ?string $value) use (&$found): void {
            if ($batchId === null) {
                return;
            }
            $found[$batchId]['matches'][] = ['on' => $on, 'value' => $value];
        };

        BatchRecord::query()->where('batch_number', 'like', $like)
            ->get(['id', 'batch_number'])
            ->each(fn (BatchRecord $b) => $register($b->id, 'Batch number', $b->batch_number));

        ManufacturingOrder::query()
            ->where('mo_number', 'like', $like)
            ->orWhere('winman_manufacturing_order_id', 'like', $like)
            ->get(['id', 'mo_number'])
            ->each(function (ManufacturingOrder $mo) use ($register): void {
                BatchRecord::query()->where('manufacturing_order_id', $mo->id)->pluck('id')
                    ->each(fn ($id) => $register((int) $id, 'MO number', $mo->mo_number));
            });

        PalleconRecord::query()
            ->where('serial_number', 'like', $like)
            ->orWhere('ticket_number', 'like', $like)
            ->get(['batch_record_id', 'serial_number'])
            ->each(fn (PalleconRecord $p) => $register($p->batch_record_id, 'Pallecon serial', $p->serial_number));

        // First-class pallecon containers: one container may hold several batches,
        // so a serial match must register every contributing batch.
        Pallecon::query()
            ->where('serial_number', 'like', $like)
            ->with('fills:id,pallecon_id,batch_record_id')
            ->get(['id', 'serial_number'])
            ->each(function (Pallecon $pallecon) use ($register): void {
                foreach ($pallecon->fills as $fill) {
                    $register($fill->batch_record_id, 'Pallecon serial', $pallecon->serial_number);
                }
            });

        PalletRecord::query()
            ->with(['packingRun:id,batch_record_id', 'drumProcessingRun:id,batch_record_id'])
            ->where('pallet_number', 'like', $like)
            ->orWhere('ticket_number', 'like', $like)
            ->get()
            ->each(function (PalletRecord $pallet) use ($register): void {
                $batchId = $pallet->packingRun?->batch_record_id ?? $pallet->drumProcessingRun?->batch_record_id;
                $register($batchId, 'Finished pallet', $pallet->pallet_number);
            });

        DrumRecord::query()
            ->with('pallet.drumProcessingRun:id,batch_record_id')
            ->where('drum_number', 'like', $like)
            ->get()
            ->each(fn (DrumRecord $d) => $register($d->pallet?->drumProcessingRun?->batch_record_id, 'Drum', $d->drum_number));

        DrumProcessingPallet::query()
            ->with('drumProcessingRun:id,batch_record_id')
            ->where('pallet_ticket_number', 'like', $like)
            ->get()
            ->each(fn (DrumProcessingPallet $p) => $register($p->drumProcessingRun?->batch_record_id, 'Drum pallet ticket', $p->pallet_ticket_number));

        return $this->hydrate($found);
    }

    /**
     * @param  array<int, array{matches: array<int, array{on: string, value: ?string}>}>  $found
     * @return array<int, array{batch: BatchRecord, matches: array<int, array{on: string, value: ?string}>}>
     */
    private function hydrate(array $found): array
    {
        $batches = BatchRecord::query()->whereIn('id', array_keys($found))->get()->keyBy('id');

        $result = [];
        foreach ($found as $batchId => $data) {
            if ($batch = $batches->get($batchId)) {
                $result[] = ['batch' => $batch, 'matches' => $data['matches']];
            }
        }

        return $result;
    }
}
