<?php

namespace App\Events\Auth;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The welcome card the SPA shows as a toast right after signing in. Goes out over
 * Reverb on the user's private channel as `.auth.welcome`.
 *
 * Broadcast immediately: it is always dispatched from a queued listener that
 * already waited the configured delay, so a second hop through the queue would
 * add nothing.
 */
class UserWelcomed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly User $user) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->user->id)];
    }

    public function broadcastAs(): string
    {
        return 'auth.welcome';
    }

    /**
     * @return array{title: string, body: string, user: array{id: int, name: string|null}}
     */
    public function broadcastWith(): array
    {
        /** @var string $app */
        $app = config('app.name');
        $name = $this->user->name ?: $this->user->email;

        return [
            'title' => "Bienvenido a {$app}, {$name}",
            'body' => 'Tu sesión quedó abierta y este canal ya está conectado: por aquí llegarán los avisos de tus despliegues en tiempo real.',
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
        ];
    }
}
