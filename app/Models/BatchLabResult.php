<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DBMTS Batch Lab Result (scope §8): a QA pass/fail decision recorded against a
 * production batch. A fail triggers automatic hold propagation to the batch and
 * any pallecons it contributed to.
 */
class BatchLabResult extends Model
{
    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';
    public const RESULT_PENDING = 'pending';

    protected $fillable = [
        'batch_record_id',
        'result',
        'analytical_spec',
        'comment',
        'tested_at',
        'tested_by',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return ['tested_at' => 'datetime'];
    }

    public function batchRecord(): BelongsTo
    {
        return $this->belongsTo(BatchRecord::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
