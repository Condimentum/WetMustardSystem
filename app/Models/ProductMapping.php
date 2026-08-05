<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'structure_product',
        'structure_product_id',
        'structure_classification',
        'structure_product_description',
        'structure_unit_of_measure_description',
        'structure_boxes_per_pallet',
        'structure_batch_size_kg',
        'component_product',
        'component_classification',
        'component_product_id',
        'component_product_description',
        'quantity_per_unit',
        'component_valid_from',
        'component_valid_to',
        'structure_level',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'structure_product' => 'integer',
            'structure_boxes_per_pallet' => 'integer',
            'structure_batch_size_kg' => 'decimal:5',
            'component_product' => 'integer',
            'quantity_per_unit' => 'decimal:5',
            'component_valid_from' => 'datetime',
            'component_valid_to' => 'datetime',
            'structure_level' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
