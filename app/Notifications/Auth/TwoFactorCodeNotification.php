<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * The one-time code that completes a sign-in. Queued, like every outbound email
 * of this app, so the request never waits on SMTP; Horizon delivers it.
 *
 * Retried on a transient SMTP failure (ZeptoMail is an external service): three attempts,
 * 10 s then 60 s apart, so the email arrives late rather than never. Both delays stay far
 * below the lifetime of what the email carries.
 */
#[Tries(3)]
#[Backoff([10, 60])]
class TwoFactorCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        private readonly int $expireMinutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Horizon tags (business rule: every queued job carries them). `mail` is the stable
     * category to monitor; the second tag tells this email apart from the other ones.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['mail', 'mail:two-factor'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        /** @var string $app */
        $app = config('app.name');

        return (new MailMessage)
            ->subject("{$this->code} es tu código de acceso a {$app}")
            ->greeting($notifiable->name !== '' ? "Hola, {$notifiable->name}." : '¡Hola!')
            ->line("Para terminar de entrar a {$app}, escribe este código en la pantalla de acceso:")
            ->line("**{$this->code}**")
            ->line("Vale por {$this->expireMinutes} minutos y solo se puede usar una vez.")
            ->line('Si no intentaste entrar, cambia tu contraseña: alguien más la conoce.')
            ->salutation("El equipo de {$app}");
    }
}
