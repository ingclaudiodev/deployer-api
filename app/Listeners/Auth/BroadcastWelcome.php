<?php

namespace App\Listeners\Auth;

use App\Events\Auth\SessionStarted;
use App\Events\Auth\UserWelcomed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pushes the welcome card to the user who just signed in.
 *
 * Queued with a delay on purpose: the sign-in response carries the token the
 * browser needs before it can open the WebSocket and subscribe to its channel.
 * Reverb keeps no history, so a card sent before that moment is lost.
 */
class BroadcastWelcome implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(SessionStarted $event): void
    {
        UserWelcomed::dispatch($event->user);
    }

    /**
     * Horizon tags (business rule: every queued job carries them). Horizon adds the
     * `App\Models\User:{id}` tag itself from the event, so only the category goes here.
     * Horizon builds the listener without its constructor to read these: keep them static.
     *
     * @return list<string>
     */
    public function tags(SessionStarted $event): array
    {
        return ['broadcast', 'broadcast:welcome'];
    }

    /**
     * Seconds the queued job waits before running (see `auth.welcome.delay_seconds`).
     */
    public function withDelay(SessionStarted $event): int
    {
        return (int) config('auth.welcome.delay_seconds');
    }
}
