<?php

use App\Http\Controllers\Auth\MicrosoftAuthCallbackController;
use App\Http\Controllers\Auth\MicrosoftAuthRedirectController;
use App\Http\Controllers\AuditTrailExportController;
use App\Http\Controllers\BatchRecordExportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', HomeController::class)
    ->name('home');

// TEMPORARY (local only): one-click dev login that bypasses OAuth and the
// Livewire login form. Remove before any non-local deployment.
if (app()->environment('local')) {
    Route::get('dev-login', function () {
        $allowed = collect((array) config('dbmts.temporary_login_allow_emails', []))
            ->map(fn (string $email): string => \Illuminate\Support\Str::lower(trim($email)))
            ->filter()
            ->values();

        $user = $allowed->isNotEmpty()
            ? \App\Models\User::query()->whereIn('email', $allowed->all())->first()
            : (\App\Models\User::where('email', 'test@example.com')->first() ?? \App\Models\User::query()->first());

        if (! $user) {
            abort(403, 'Dev login is restricted by temporary allowlist.');
        }

        Auth::login($user);
        request()->session()->regenerate();

        app(\App\Domains\Auth\Jobs\SyncUserRolesJob::class)($user);

        return redirect()->route('dashboard');
    })->name('dev-login');
}

Route::middleware('guest')->group(function () {
    Route::get('auth/microsoft/redirect', MicrosoftAuthRedirectController::class)
        ->name('auth.microsoft.redirect');

    Route::get('auth/microsoft/callback', MicrosoftAuthCallbackController::class)
        ->name('auth.microsoft.callback');
});

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('metal-detector/daily', 'pages.metal-detector.daily')
        ->name('metal-detector.daily');

    Volt::route('manufacturing-orders', 'pages.manufacturing-orders.search')
        ->name('manufacturing-orders.search');

    Volt::route('manufacturing-orders/{winmanMo}/workspace', 'pages.manufacturing-orders.workspace')
        ->name('manufacturing-orders.workspace');

    Route::get('production/ibc', \App\Http\Controllers\IbcProductionController::class)
        ->name('production.ibc');

    Route::get('production/bucketing', \App\Http\Controllers\BucketingController::class)
        ->name('production.bucketing');

    Volt::route('calibrations', 'pages.calibrations.daily')
        ->name('calibrations.daily');

    Volt::route('quality/lab-testing', 'pages.quality-lab-testing.index')
        ->name('quality.lab-testing');

    Volt::route('quality/lab-testing/ibc-traceability', 'pages.quality-lab-testing.ibc-traceability')
        ->name('quality.lab-testing.ibc-traceability');

    Volt::route('quality/lab-testing/wet-mustard-lab', 'pages.quality-lab-testing.wet-mustard-lab')
        ->name('quality.lab-testing.wet-mustard-lab');

    Volt::route('quality/lab-testing/rinse-water-test', 'pages.quality-lab-testing.rinse-water-test')
        ->name('quality.lab-testing.rinse-water-test');

    Volt::route('batches/{batch}', 'pages.batches.show')
        ->name('batches.show');

    Volt::route('batches/{batch}/pallecons', 'pages.batches.pallecons')
        ->name('batches.pallecons');

    Volt::route('batches/{batch}/packing', 'pages.batches.packing')
        ->name('batches.packing');


    Volt::route('traceability', 'pages.traceability.search')
        ->name('traceability.search');

    Volt::route('reporting', 'pages.reporting.admin')
        ->middleware('can:admin')
        ->name('reporting.admin');

    Volt::route('notifications', 'pages.notifications.admin')
        ->middleware('can:admin')
        ->name('notifications.admin');

    Volt::route('audit', 'pages.audit.index')
        ->middleware('can:admin')
        ->name('audit.index');

    Volt::route('settings', 'pages.settings.admin')
        ->middleware('can:admin')
        ->name('settings.admin');

    Volt::route('settings/recipes', 'pages.recipes.index')
        ->middleware('can:admin')
        ->name('settings.recipes');

    Volt::route('settings/product-mapping', 'pages.settings.product-mapping')
        ->middleware('can:admin')
        ->name('settings.product-mapping');

    Volt::route('settings/operator-sync', 'pages.settings.operator-sync')
        ->middleware('can:admin')
        ->name('settings.operator-sync');

    Volt::route('settings/documents', 'pages.settings.documents')
        ->middleware('can:admin')
        ->name('settings.documents');

    Route::redirect('recipes', 'settings/recipes')
        ->middleware('can:admin')
        ->name('recipes.index');

    Route::get('audit/export', AuditTrailExportController::class)
        ->middleware('can:admin')
        ->name('audit.export');

    Route::get('batches/{batch}/export', BatchRecordExportController::class)
        ->name('batches.export');
});

require __DIR__.'/auth.php';
