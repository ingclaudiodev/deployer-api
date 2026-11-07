<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Deployer's own wording for the password reset email. The link itself comes from
 * ResetPassword::createUrlUsing(), configured in AuthServiceProvider, so it opens the SPA.
 */
class ResetPasswordNotification extends ResetPassword
{
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
