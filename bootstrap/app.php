<?php

use App\Domains\Audit\Jobs\RecordErrorLogJob;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Feeds the Settings > Error Log "event viewer" with every problem an
        // operator can hit (validation failures, SQL errors, uncaught domain
        // exceptions), skipping routine HTTP/auth navigation noise (404s,
        // login redirects, CSRF token mismatches).
        $logToErrorLog = function (Throwable $e, $request = null) {
            $isRoutineNoise = $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof TokenMismatchException
                || $e instanceof HttpExceptionInterface;

            if ($isRoutineNoise) {
                return;
            }

            try {
                app(RecordErrorLogJob::class)($e, $request?->route()?->getName());
            } catch (Throwable) {
                // Never let error logging itself break the response.
            }
        };

        // Covers explicit report($e) calls made by components that already
        // catch an exception locally to show a friendly message.
        $exceptions->reportable(function (Throwable $e) use ($logToErrorLog) {
            $logToErrorLog($e, request());
        });

        // Covers everything else (validation failures, SQL errors and any
        // other uncaught exception) as it is rendered back to the browser.
        $exceptions->renderable(function (Throwable $e, $request) use ($logToErrorLog) {
            $logToErrorLog($e, $request);

            return null;
        });
    })->create();
