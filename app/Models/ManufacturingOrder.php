<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DBMTS local record of a selected WinMan manufacturing order (scope entity:
 * ManufacturingOrder). DBMTS consumes existing WinMan MOs only.
 */
class ManufacturingOrder extends Model
{
    protected $fillable = [
        'mo_number',
        'winman_manufacturing_order',
        'winman_manufacturing_order_id',
        'winman_product_internal',
        'winman_product_id',
        'recipe_code',
        'variant_id',
        'product_id',
        'planned_quantity',
        'quantity_outstanding',
        'winman_classification',
        'winman_system_type',
        'winman_unit_of_measure',
        'winman_unit_of_measure_description',
        'winman_last_modified_date',
        'status',
        'selected_by',
    ];

    protected function casts(): array
    {
        return [
            'winman_manufacturing_order' => 'integer',
            'planned_quantity' => 'decimal:3',
            'quantity_outstanding' => 'decimal:3',
            'winman_classification' => 'integer',
            'winman_unit_of_measure' => 'integer',
            'winman_last_modified_date' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(RecipeVariant::class, 'variant_id');
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function componentSnapshots(): HasMany
    {
        return $this->hasMany(WinManMoComponentSnapshot::class);
    }
}
