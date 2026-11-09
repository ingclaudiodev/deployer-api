<?php

use App\Events\Auth\SessionStarted;
use App\Listeners\Auth\BroadcastWelcome;
use App\Models\User;
use App\Notifications\Alerts\AlertSeverity;
use App\Notifications\Alerts\ClusterAlertNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\AnonymousNotifiable as LaravelAnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Laravel\Horizon\Tags;

test('the two-factor code email is tagged as mail in Horizon', function (): void {
    $job = new SendQueuedNotifications(User::factory()->make(), new TwoFactorCodeNotification('123456', 10));

    expect(Tags::for($job))->toBe(['mail', 'mail:two-factor']);
});

test('the password reset email is tagged as mail in Horizon', function (): void {
    $job = new SendQueuedNotifications(User::factory()->make(), new ResetPasswordNotification('token'));

    expect(Tags::for($job))->toBe(['mail', 'mail:reset-password']);
});

test('a cluster alert is tagged with its channel and severity in Horizon', function (): void {
    $notifiable = (new LaravelAnonymousNotifiable)->route('slack', 'https://hooks.slack.test/x');
    $job = new SendQueuedNotifications($notifiable, new ClusterAlertNotification('Deploy fallido', 'exit 1', [], AlertSeverity::Critical));

    expect(Tags::for($job))->toBe(['slack', 'slack:cluster-alert', 'alert:critical']);
});

test('the welcome push keeps the user tag next to its own tags in Horizon', function (): void {
    $user = User::factory()->make(['id' => 42]);
    $job = new CallQueuedListener(BroadcastWelcome::class, 'handle', [new SessionStarted($user)]);

    expect(Tags::for($job))->toBe(['broadcast', 'broadcast:welcome', User::class.':42']);
});
