<?php

namespace App\Features\Booking;

use App\Domains\Audit\Jobs\RecordAuditEntryJob;
use App\Models\BatchRecord;
use App\Models\User;
use App\Models\WinManBookingLog;
use App\Operations\BookFinishedGoodsToMoOperation;
use Carbon\CarbonInterface;

/**
 * Books completed finished goods for a batch against its existing WinMan MO and
 * records an audit entry (scope acceptance criteria 2, 4).
 */
class BookFinishedGoodsFeature
{
    public function __construct(
        private readonly BookFinishedGoodsToMoOperation $bookFinishedGoods,
        private readonly RecordAuditEntryJob $recordAuditEntry,
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
        $log = ($this->bookFinishedGoods)(
            $batch,
            $quantityKg,
            $lotNumber,
            $duplicateCheckLots,
            $finishedDate,
            $expiryDate,
            $user,
            $allowMultiplePerBatch,
        );

        ($this->recordAuditEntry)($log, 'winman_booking', $user, 'booking_status', null, $log->booking_status);

        return $log;
    }
}
