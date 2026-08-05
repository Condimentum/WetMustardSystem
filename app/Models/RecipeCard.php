<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeCard extends Model
{
    protected $fillable = [
        'recipe_code',
        'document_reference',
        'batch_size_kg',
        'batch_sizes_kg',
        'plc_recipe_number',
        'revision_no',
        'issue_date',
        'reason_for_issue',
        'steps',
        'layout_config',
    ];

    protected function casts(): array
    {
        return [
            'batch_size_kg' => 'decimal:3',
            'batch_sizes_kg' => 'array',
            'issue_date' => 'date',
            'steps' => 'array',
            'layout_config' => 'array',
        ];
    }
}
