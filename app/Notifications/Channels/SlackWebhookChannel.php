<?php

namespace App\Notifications\Channels;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\Notification;
use LogicException;
use RuntimeException;

/**
 * Posts a notification to a Slack incoming webhook. The URL comes from the
 * notifiable's "slack" route or, failing that, from services.slack.webhook_url,
 * so a model without its own channel still reaches the team's alert channel.
 */
class SlackWebhookChannel
{
    public function __construct(private readonly HttpClient $http) {}

    /**
     * @throws RequestException when Slack rejects the message, so the queued job retries
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSlack')) {
            throw new LogicException(sprintf('%s must define toSlack() to use %s.', $notification::class, self::class));
        }

        $this->http
            ->asJson()
            ->post($this->webhookUrlFor($notifiable, $notification), $notification->toSlack($notifiable))
            ->throw();
    }

    private function webhookUrlFor(object $notifiable, Notification $notification): string
    {
        $url = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('slack', $notification)
            : null;

        $url = is_string($url) && $url !== '' ? $url : config('services.slack.webhook_url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('No Slack webhook configured: set SLACK_WEBHOOK_URL or route the notification to one.');
        }

        return $url;
    }
}
