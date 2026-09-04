<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local snapshot of an outstanding WinMan manufacturing order, refreshed by
 * SyncOutstandingManufacturingOrdersJob. Used only as a fallback data source
 * for the MO Search screen when WinMan is unreachable - never authoritative
 * (the live WinMan query is always preferred when WinMan is up).
 */
class WinManSyncedManufacturingOrder extends Model
{
    protected $table = 'winman_mo_sync_cache';

    protected $fillable = [
        'winman_manufacturing_order',
        'winman_manufacturing_order_id',
        'winman_product_internal',
        'winman_product_id',
        'product_description',
        'system_type',
        'planned_quantity',
        'quantity_outstanding',
        'classification',
        'unit_of_measure',
        'unit_of_measure_description',
        'due_date',
        'last_modified_date',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'winman_manufacturing_order' => 'integer',
            'planned_quantity' => 'decimal:3',
            'quantity_outstanding' => 'decimal:3',
            'classification' => 'integer',
            'unit_of_measure' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Same shape as ManufacturingOrderData::toArray(), so MO Search's
     * enrichment logic (recipe mapping, variants, etc.) works identically
     * whether the raw list came live from WinMan or from this local cache.
     *
     * @return array<string, mixed>
     */
    public function toOrderArray(): array
    {
        return [
            'winman_manufacturing_order' => (int) $this->winman_manufacturing_order,
            'winman_manufacturing_order_id' => (string) $this->winman_manufacturing_order_id,
            'winman_product_internal' => $this->winman_product_internal,
            'winman_product_id' => $this->winman_product_id,
            'product_description' => (string) $this->product_description,
            'system_type' => (string) $this->system_type,
            'planned_quantity' => (float) $this->planned_quantity,
            'quantity_outstanding' => (float) $this->quantity_outstanding,
            'classification' => $this->classification !== null ? (int) $this->classification : null,
            'unit_of_measure' => $this->unit_of_measure !== null ? (int) $this->unit_of_measure : null,
            'unit_of_measure_description' => $this->unit_of_measure_description,
            'due_date' => $this->due_date,
            'last_modified_date' => $this->last_modified_date,
        ];
    }
}
