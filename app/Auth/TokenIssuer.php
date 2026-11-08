<?php

namespace App\Auth;

use App\Events\Auth\SessionStarted;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The single place where an authenticated User becomes an API session.
 *
 * Every adapter ends here, so token naming, scopes and lifetime stay consistent
 * regardless of how the user proved their identity. It is also where
 * {@see SessionStarted} is raised, so anything that must follow a sign-in
 * (the welcome broadcast, audit trails...) hooks there instead of in controllers.
 */
class TokenIssuer
{
    public function __construct(
        private readonly string $tokenName,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  list<string>  $scopes
     */
    public function issue(User $user, array $scopes = []): IssuedToken
    {
        $result = $user->createToken($this->tokenName, $scopes);
        $expiresAt = $result->getToken()?->expires_at;

        $issued = new IssuedToken(
            user: $user,
            accessToken: $result->accessToken,
            expiresAt: $expiresAt === null ? null : CarbonImmutable::instance($expiresAt),
        );

        $this->events->dispatch(new SessionStarted($user));

        return $issued;
    }
}
