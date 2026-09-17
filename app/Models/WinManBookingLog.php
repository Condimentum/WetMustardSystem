<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DBMTS WinMan Booking Log (scope entity: WinManBookingLog).
 */
class WinManBookingLog extends Model
{
    protected $table = 'winman_booking_logs';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'batch_record_id', 'pallecon_id', 'winman_inventory_id', 'winman_manufacturing_order', 'winman_manufacturing_order_id',
        'winman_product_internal', 'winman_product_id', 'batch_number', 'lot_number',
        'quantity_booked_kg', 'quantity_booked_traded_units', 'booking_user', 'booking_date',
        'booking_status', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'winman_manufacturing_order' => 'integer',
            'quantity_booked_kg' => 'decimal:3',
            'quantity_booked_traded_units' => 'decimal:3',
            'booking_date' => 'datetime',
        ];
    }

    public function batchRecord(): BelongsTo
    {
        return $this->belongsTo(BatchRecord::class);
    }

    public function pallecon(): BelongsTo
    {
        return $this->belongsTo(Pallecon::class);
    }
}
