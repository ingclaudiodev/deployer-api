<?php

namespace App\Notifications\Alerts;

use App\Notifications\Channels\SlackWebhookChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A business alert for the #deployer-cluster-alertas channel. Queued, like every
 * outbound notice of this app, so the request never waits on Slack; Horizon posts it.
 *
 * Usage:
 *   Notification::route('slack', config('services.slack.webhook_url'))
 *       ->notify(new ClusterAlertNotification('Deploy fallido', 'El build 42 salió con exit 1.', ['Proyecto' => 'acme-api'], AlertSeverity::Critical));
 */
class ClusterAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $context  Short key/value pairs shown as fields under the message.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly array $context = [],
        public readonly AlertSeverity $severity = AlertSeverity::Warning,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [SlackWebhookChannel::class];
    }

    /**
     * Horizon tags (business rule: every queued job carries them). The notifiable is anonymous
     * (a webhook URL), so without these the job would show up untagged. `alert:<severity>` lets
     * the dashboard monitor critical alerts on their own.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['slack', 'slack:cluster-alert', 'alert:'.$this->severity->value];
    }

    /**
     * The incoming-webhook payload: a headline plus one coloured attachment with the detail.
     *
     * @return array{text: string, attachments: list<array{color: string, text: string, fields: list<array{title: string, value: string, short: bool}>, footer: string, ts: int}>}
     */
    public function toSlack(object $notifiable): array
    {
        /** @var string $app */
        $app = config('app.name');
        /** @var string $env */
        $env = config('app.env');

        $fields = [];

        foreach ($this->context as $label => $value) {
            $fields[] = ['title' => (string) $label, 'value' => (string) ($value ?? '—'), 'short' => true];
        }

        return [
            'text' => "{$this->severity->emoji()} *{$this->title}*",
            'attachments' => [[
                'color' => $this->severity->color(),
                'text' => $this->message,
                'fields' => $fields,
                'footer' => "{$app} · {$env}",
                'ts' => now()->getTimestamp(),
            ]],
        ];
    }
}
