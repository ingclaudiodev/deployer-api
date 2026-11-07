<?php

use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Auth\TwoFactorResendController;
use App\Http\Controllers\Api\Auth\TwoFactorVerifyController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('register', RegisterController::class)->name('register');
        Route::post('login', LoginController::class)->name('login');
        Route::post('forgot-password', ForgotPasswordController::class)->name('forgot-password');
        Route::post('reset-password', ResetPasswordController::class)->name('reset-password');
    });

    // Second step of the sign-in; limited per challenge + ip, not per email (the body carries none).
    Route::middleware('throttle:two-factor')->prefix('login')->name('login.')->group(function (): void {
        Route::post('verify', TwoFactorVerifyController::class)->name('verify');
        Route::post('resend', TwoFactorResendController::class)->name('resend');
    });

    Route::middleware('auth:api')->group(function (): void {
        Route::get('me', MeController::class)->name('me');
        Route::post('logout', LogoutController::class)->name('logout');
    });
});
