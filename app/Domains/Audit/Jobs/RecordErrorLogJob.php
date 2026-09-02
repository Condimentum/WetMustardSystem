<?php

namespace App\Domains\Audit\Jobs;

use App\Models\ErrorLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Records a problem encountered while operators book through checks and
 * production (validation failure, SQL/DB error, domain exception or any other
 * uncaught exception) into the Error Log "event viewer".
 */
class RecordErrorLogJob
{
    public function __invoke(Throwable $exception, ?string $context = null, ?string $level = null): ErrorLog
    {
        $level ??= match (true) {
            $exception instanceof ValidationException => ErrorLog::LEVEL_VALIDATION,
            $exception instanceof QueryException => ErrorLog::LEVEL_CRITICAL,
            default => ErrorLog::LEVEL_ERROR,
        };

        $request = request();

        return ErrorLog::create([
            'level' => $level,
            'exception_class' => $exception::class,
            'message' => Str::limit($exception->getMessage(), 2000, ''),
            'context' => $context,
            'url' => $request?->fullUrl(),
            'http_method' => $request?->method(),
            'user_id' => auth()->id(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => Str::limit($exception->getTraceAsString(), 4000, ''),
        ]);
    }
}
