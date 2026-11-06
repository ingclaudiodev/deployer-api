<?php

namespace App\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A freshly issued access token together with the user it belongs to.
 */
final readonly class IssuedToken
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public ?CarbonImmutable $expiresAt,
        public string $tokenType = 'Bearer',
    ) {}
}
