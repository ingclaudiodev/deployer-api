<?php

use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\LoginCodeController;
use App\Http\Controllers\Panel\LoginController;
use App\Http\Controllers\Panel\LogoutController;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome', [
    'frontendUrl' => fn (): string => (string) config('app.frontend_url'),
])->name('home');

// Operations panel: a browser session of the same users the API authenticates.
Route::prefix('panel')->name('panel.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('login', [LoginController::class, 'show'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->middleware('throttle:auth')->name('login.store');

        Route::get('login/code', [LoginCodeController::class, 'show'])->name('login.code');

        // Limited per ip: the challenge id stays in the session, the body carries none.
        Route::middleware('throttle:two-factor')->group(function (): void {
            Route::post('login/code', [LoginCodeController::class, 'store'])->name('login.code.verify');
            Route::post('login/code/resend', [LoginCodeController::class, 'resend'])->name('login.code.resend');
        });
    });

    Route::middleware(['auth', EnsureSuperAdmin::class])->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('logout', LogoutController::class)->name('logout');
    });
});
