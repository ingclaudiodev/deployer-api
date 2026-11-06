<?php

namespace App\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The single place where an authenticated User becomes an API session.
 *
 * Every adapter ends here, so token naming, scopes and lifetime stay consistent
 * regardless of how the user proved their identity.
 */
class TokenIssuer
{
    public function __construct(private readonly string $tokenName) {}

    /**
     * @param  list<string>  $scopes
     */
    public function issue(User $user, array $scopes = []): IssuedToken
    {
        $result = $user->createToken($this->tokenName, $scopes);
        $expiresAt = $result->getToken()?->expires_at;

        return new IssuedToken(
            user: $user,
            accessToken: $result->accessToken,
            expiresAt: $expiresAt === null ? null : CarbonImmutable::instance($expiresAt),
        );
    }
}
