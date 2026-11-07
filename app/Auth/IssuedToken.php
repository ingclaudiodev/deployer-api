<?php

namespace App\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A freshly issued access token together with the user it belongs to, plus the
 * device token when the client asked to be remembered for the second factor.
 */
final readonly class IssuedToken
{
    public function __construct(
        public User $user,
        public string $accessToken,
        public ?CarbonImmutable $expiresAt,
        public string $tokenType = 'Bearer',
        public ?string $deviceToken = null,
    ) {}

    public function withDeviceToken(string $deviceToken): self
    {
        return new self($this->user, $this->accessToken, $this->expiresAt, $this->tokenType, $deviceToken);
    }
}
