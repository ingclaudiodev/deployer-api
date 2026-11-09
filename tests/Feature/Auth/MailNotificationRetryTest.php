<?php

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Illuminate\Notifications\SendQueuedNotifications;

test('the two-factor code email is retried with backoff when SMTP fails', function (): void {
    $user = User::factory()->make();
    $job = new SendQueuedNotifications($user, new TwoFactorCodeNotification('123456', 10));

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});

test('the password reset email is retried with backoff when SMTP fails', function (): void {
    $user = User::factory()->make();
    $job = new SendQueuedNotifications($user, new ResetPasswordNotification('token'));

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});
