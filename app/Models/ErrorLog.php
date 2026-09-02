<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DBMTS Error Log (scope entity: ErrorLog).
 *
 * Event-viewer style record of problems encountered while operators book
 * through checks and production: validation failures, SQL/DB errors, domain
 * exceptions and any other uncaught exception.
 */
class ErrorLog extends Model
{
    public const LEVEL_VALIDATION = 'validation';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_CRITICAL = 'critical';

    protected $fillable = [
        'level',
        'exception_class',
        'message',
        'context',
        'url',
        'http_method',
        'user_id',
        'file',
        'line',
        'trace',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
