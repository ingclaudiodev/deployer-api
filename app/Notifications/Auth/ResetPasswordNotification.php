<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * Deployer's own wording for the password reset email. The link itself comes from
 * ResetPassword::createUrlUsing(), configured in AuthServiceProvider, so it opens the SPA.
 * Queued, like every outbound email of this app.
 *
 * Retried on a transient SMTP failure (ZeptoMail is an external service): three attempts,
 * 10 s then 60 s apart, so the email arrives late rather than never. Both delays stay far
 * below the lifetime of what the email carries.
 */
#[Tries(3)]
#[Backoff([10, 60])]
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        /** @var int $minutes */
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Restablece tu contraseña de '.config('app.name'))
            ->greeting($notifiable->name !== '' ? "Hola, {$notifiable->name}." : '¡Hola!')
            ->line('Recibimos una solicitud para cambiar la contraseña de tu cuenta en '.config('app.name').'.')
            ->action('Crear nueva contraseña', $this->resetUrl($notifiable))
            ->line("El enlace vale por {$minutes} minutos y solo se puede usar una vez.")
            ->line('Si no pediste este cambio, ignora este correo: tu contraseña sigue igual.')
            ->salutation('El equipo de '.config('app.name'));
    }
}
