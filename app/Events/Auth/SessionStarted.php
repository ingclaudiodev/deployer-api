<?php

namespace App\Events\Auth;

use App\Auth\TokenIssuer;
use App\Listeners\Auth\BroadcastWelcome;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user just got an API session, whichever way they proved who they are
 * (password, emailed code, fresh sign-up). Raised by {@see TokenIssuer}.
 *
 * Plain domain event: it does not broadcast itself. Side effects that must
 * follow a sign-in are queued listeners, like {@see BroadcastWelcome}, so the
 * user travels through the queue by key only, never with its attributes.
 */
class SessionStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly User $user) {}
}
