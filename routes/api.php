<?php

use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Auth\TwoFactorResendController;
use App\Http\Controllers\Api\Auth\TwoFactorVerifyController;
use App\Http\Controllers\Api\Pricing\IndexController as PricingIndexController;
use App\Http\Controllers\Api\Projects\AnalysisController;
use App\Http\Controllers\Api\Projects\AnalyzeController;
use App\Http\Controllers\Api\Projects\ApproveController;
use App\Http\Controllers\Api\Projects\DeploymentBranchController;
use App\Http\Controllers\Api\Projects\DeploymentFileController;
use App\Http\Controllers\Api\Projects\DestroyController;
use App\Http\Controllers\Api\Projects\IndexController;
use App\Http\Controllers\Api\Projects\OptionsController;
use App\Http\Controllers\Api\Projects\ShowController;
use App\Http\Controllers\Api\Projects\StoreController;
use App\Http\Controllers\Api\Projects\UpdateController;
use App\Http\Controllers\Api\Repositories\BranchIndexController;
use App\Http\Controllers\Api\Repositories\CallbackController;
use App\Http\Controllers\Api\Repositories\ConnectController;
use App\Http\Controllers\Api\Repositories\ConnectionIndexController;
use App\Http\Controllers\Api\Repositories\DisconnectController;
use App\Http\Controllers\Api\Repositories\RepositoryIndexController;
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

// Projects of the signed-in user. `{project}` resolves only among their own
// (AppServiceProvider), so somebody else's id answers 404 like one that does not exist.
Route::middleware('auth:api')->prefix('projects')->name('projects.')->group(function (): void {
    Route::get('/', IndexController::class)->name('index');
    Route::post('/', StoreController::class)->name('store');
    Route::get('options', OptionsController::class)->name('options');
    Route::get('{project}', ShowController::class)->name('show')->whereNumber('project');
    Route::patch('{project}', UpdateController::class)->name('update')->whereNumber('project');
    Route::delete('{project}', DestroyController::class)->name('destroy')->whereNumber('project');

    // From a repository to a deployment to approve: the analysis runs in a queue and its
    // result lives in the bucket; the branch is an optional copy in the owner's repository.
    Route::post('{project}/analysis', AnalyzeController::class)->name('analysis.store')->whereNumber('project');
    Route::get('{project}/analysis', AnalysisController::class)->name('analysis.show')->whereNumber('project');
    Route::get('{project}/analysis/file', DeploymentFileController::class)->name('analysis.file')->whereNumber('project');
    Route::post('{project}/approval', ApproveController::class)->name('approval.store')->whereNumber('project');
    Route::post('{project}/deployment-branch', DeploymentBranchController::class)->name('deployment-branch.store')->whereNumber('project');
});

// GitHub and GitLab accounts of the signed-in user. `{provider}` is a `RepositoryProvider`:
// any other value answers 404.
Route::prefix('repositories')->name('repositories.')->group(function (): void {
    Route::middleware('auth:api')->group(function (): void {
        Route::get('connections', ConnectionIndexController::class)->name('connections');
        Route::post('{provider}/connect', ConnectController::class)->name('connect');
        Route::get('{provider}/repos', RepositoryIndexController::class)->name('repos');
        Route::get('{provider}/branches', BranchIndexController::class)->name('branches');
        Route::delete('{provider}', DisconnectController::class)->name('disconnect');
    });

    // The host sends the browser here after the user answered: no bearer token, the
    // `state` identifies the pending connection.
    Route::get('{provider}/callback', CallbackController::class)->middleware('throttle:30,1')->name('callback');
});

// Public price list: the landing shows it to visitors without a session.
Route::get('pricing', PricingIndexController::class)->name('pricing.index');
