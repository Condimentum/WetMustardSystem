<?php

namespace App\Operations;

use App\Domains\Booking\Jobs\HasBatchBeenBookedJob;
use App\Domains\Booking\Jobs\RecordWinManBookingLogJob;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\CallManufacturingOrderFinishingJob;
use App\Domains\WinMan\Jobs\CheckWinManInventoryDuplicateJob;
use App\Domains\WinMan\Jobs\GetWinManProductPackSizeJob;
use App\Domains\WinMan\Jobs\ReadMoBookingContextJob;
use App\Models\BatchRecord;
use App\Models\User;
use App\Models\WinManBookingLog;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Books completed finished goods against the selected existing WinMan MO using
 * the approved finishing stored procedure only (scope §11.5-11.6).
 *
 * Pre-booking checks (all must pass): booking enabled, MO exists with a valid
 * internal number, batch not already booked locally, MO has outstanding
 * quantity, no duplicate lot in WinMan Inventory, and LastModifiedDate
 * concurrency read immediately before booking. Every attempt is logged.
 *
 * DBMTS never creates WinMan MOs and never writes WinMan tables directly.
 */
class BookFinishedGoodsToMoOperation
{
    public function __construct(
        private readonly HasBatchBeenBookedJob $hasBatchBeenBooked,
        private readonly ReadMoBookingContextJob $readContext,
        private readonly CheckWinManInventoryDuplicateJob $checkInventoryDuplicate,
        private readonly CallManufacturingOrderFinishingJob $callFinishing,
        private readonly RecordWinManBookingLogJob $recordLog,
        private readonly GetWinManProductPackSizeJob $getPackSize,
    ) {
    }

    /**
     * @param  array<int, string>  $duplicateCheckLots
     */
    public function __invoke(
        BatchRecord $batch,
        float $quantityKg,
        string $lotNumber,
        array $duplicateCheckLots,
        CarbonInterface $finishedDate,
        CarbonInterface $expiryDate,
        ?User $user = null,
        bool $allowMultiplePerBatch = false,
    ): WinManBookingLog {
        if (! config('winman.booking.enabled')) {
            throw new WinManException('WinMan finished-goods booking is disabled.');
        }

        $mo = $batch->manufacturingOrder;
        if ($mo === null || empty($mo->winman_manufacturing_order)) {
            throw new WinManException('The batch has no linked WinMan manufacturing order.');
        }

        $winmanMo = (int) $mo->winman_manufacturing_order;
        $base = $this->baseAttributes($batch, $mo, $lotNumber, $user);

        if (! $allowMultiplePerBatch && ($this->hasBatchBeenBooked)($batch)) {
            return ($this->recordLog)($base + ['booking_status' => WinManBookingLog::STATUS_REJECTED, 'error_message' => 'Batch has already been booked.']);
        }

        $context = ($this->readContext)($winmanMo);
        if ($context === null) {
            return ($this->recordLog)($base + ['booking_status' => WinManBookingLog::STATUS_REJECTED, 'error_message' => 'MO not found in WinMan.']);
        }

        if ($context['quantity_outstanding'] <= 0) {
            return ($this->recordLog)($base + ['booking_status' => WinManBookingLog::STATUS_REJECTED, 'error_message' => 'MO has no outstanding quantity.']);
        }

        $packSize = $mo->winman_product_id !== null ? ($this->getPackSize)($mo->winman_product_id) : null;
        $tradedUnits = ($packSize !== null && $packSize > 0) ? round($quantityKg / $packSize, 3) : $quantityKg;

        if ($tradedUnits > $context['quantity_outstanding'] + 0.0001) {
            return ($this->recordLog)($base + ['booking_status' => WinManBookingLog::STATUS_REJECTED, 'error_message' => 'Booking quantity exceeds MO outstanding quantity.']);
        }

        $duplicates = ($this->checkInventoryDuplicate)($duplicateCheckLots);
        if ($duplicates !== []) {
            $matched = implode(', ', array_unique(array_column($duplicates, 'lot')));

            return ($this->recordLog)($base + ['booking_status' => WinManBookingLog::STATUS_REJECTED, 'error_message' => "Duplicate IBC numbers detected in WinMan Inventory: {$matched}."]);
        }

        try {
            $result = ($this->callFinishing)([
                'manufacturing_order' => $winmanMo,
                'quantity_to_complete' => $tradedUnits,
                'finished_date' => $finishedDate->format('Y-m-d H:i:s'),
                'expiry_date' => $expiryDate->format('Y-m-d H:i:s'),
                'location' => (int) (config('winman.booking.location_id') ?: $context['location']),
                'lot_number' => $lotNumber,
                'user_name' => (string) config('winman.booking.user_name', 'DBMTS'),
                'notes' => (string) config('winman.booking.notes', ''),
                'last_modified_date' => $context['last_modified_date'],
            ]);
        } catch (Throwable $e) {
            return ($this->recordLog)($base + [
                'booking_status' => WinManBookingLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'quantity_booked_kg' => $quantityKg,
                'quantity_booked_traded_units' => $tradedUnits,
            ]);
        }

        return ($this->recordLog)($base + [
            'booking_status' => WinManBookingLog::STATUS_SUCCESS,
            'winman_inventory_id' => $result['completed_inventory'],
            'quantity_booked_kg' => $quantityKg,
            'quantity_booked_traded_units' => $tradedUnits,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(BatchRecord $batch, \App\Models\ManufacturingOrder $mo, string $lotNumber, ?User $user): array
    {
        return [
            'batch_record_id' => $batch->id,
            'winman_manufacturing_order' => (int) $mo->winman_manufacturing_order,
            'winman_manufacturing_order_id' => $mo->winman_manufacturing_order_id,
            'winman_product_internal' => $mo->winman_product_internal,
            'winman_product_id' => $mo->winman_product_id,
            'batch_number' => $batch->batch_number,
            'lot_number' => $lotNumber,
            'booking_user' => $user?->name ?? (string) config('winman.booking.user_name', 'DBMTS'),
        ];
    }
}
