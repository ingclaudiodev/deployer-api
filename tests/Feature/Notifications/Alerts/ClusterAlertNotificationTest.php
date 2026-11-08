<?php

use App\Models\User;
use App\Notifications\Alerts\AlertSeverity;
use App\Notifications\Alerts\ClusterAlertNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

const CLUSTER_ALERT_TEST_WEBHOOK = 'https://hooks.slack.com/services/T000/B000/test';

test('the alert is posted to the routed Slack webhook with its title, message and context', function (): void {
    Http::preventStrayRequests();
    Http::fake([CLUSTER_ALERT_TEST_WEBHOOK => Http::response('ok')]);

    Notification::route('slack', CLUSTER_ALERT_TEST_WEBHOOK)->notifyNow(
        new ClusterAlertNotification('Deploy fallido', 'El build 42 salió con exit 1.', ['Proyecto' => 'acme-api', 'Región' => null], AlertSeverity::Critical),
    );

    Http::assertSent(function (Request $request): bool {
        $attachment = $request['attachments'][0];

        return $request->url() === CLUSTER_ALERT_TEST_WEBHOOK
            && $request['text'] === ':rotating_light: *Deploy fallido*'
            && $attachment['color'] === 'danger'
            && $attachment['text'] === 'El build 42 salió con exit 1.'
            && $attachment['fields'] === [
                ['title' => 'Proyecto', 'value' => 'acme-api', 'short' => true],
                ['title' => 'Región', 'value' => '—', 'short' => true],
            ];
    });
});

test('the alert falls back to the configured webhook when the notifiable has no Slack route', function (): void {
    config()->set('services.slack.webhook_url', CLUSTER_ALERT_TEST_WEBHOOK);
    Http::preventStrayRequests();
    Http::fake([CLUSTER_ALERT_TEST_WEBHOOK => Http::response('ok')]);
    $user = User::factory()->create();

    $user->notifyNow(new ClusterAlertNotification('Cuota al 90 %', 'La wallet casi se agota.'));

    Http::assertSent(fn (Request $request): bool => $request->url() === CLUSTER_ALERT_TEST_WEBHOOK
        && $request['text'] === ':warning: *Cuota al 90 %*'
        && $request['attachments'][0]['color'] === 'warning');
});

test('the alert is queued instead of sent during the request', function (): void {
    Queue::fake([SendQueuedNotifications::class]);

    Notification::route('slack', CLUSTER_ALERT_TEST_WEBHOOK)->notify(new ClusterAlertNotification('Nodo caído', 'node-3 no responde.'));

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof ClusterAlertNotification);
});

test('a webhook rejected by Slack raises so the queued job can retry', function (): void {
    Http::preventStrayRequests();
    Http::fake([CLUSTER_ALERT_TEST_WEBHOOK => Http::response('invalid_payload', 400)]);

    expect(fn () => Notification::route('slack', CLUSTER_ALERT_TEST_WEBHOOK)->notifyNow(new ClusterAlertNotification('Nodo caído', 'node-3 no responde.')))
        ->toThrow(RequestException::class);
});

test('the alert raises when neither a route nor SLACK_WEBHOOK_URL is configured', function (): void {
    config()->set('services.slack.webhook_url', null);
    Http::preventStrayRequests();
    $user = User::factory()->create();

    expect(fn () => $user->notifyNow(new ClusterAlertNotification('Nodo caído', 'node-3 no responde.')))
        ->toThrow(RuntimeException::class, 'No Slack webhook configured');

    Http::assertNothingSent();
});
