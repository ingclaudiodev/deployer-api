<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one-time code that completes a sign-in. Queued, like every outbound email
 * of this app, so the request never waits on SMTP; Horizon delivers it.
 */
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
