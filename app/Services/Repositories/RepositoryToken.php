<?php

namespace App\Services\Repositories;

use Carbon\CarbonImmutable;

/**
 * The tokens a repository host hands out, in one shape for every host.
 */
final readonly class RepositoryToken
{
    /**
     * @param  CarbonImmutable|null  $expiresAt  `null` when the access token does not expire.
     * @param  CarbonImmutable|null  $refreshTokenExpiresAt  `null` when the host sets no deadline for it.
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $refreshTokenExpiresAt = null,
    ) {}

    /**
     * The answer of an OAuth 2 token endpoint, whichever grant produced it;
     * `null` when it carries no access token. Lifetimes come in seconds from now.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromOAuthPayload(array $payload): ?self
    {
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        return new self(
            accessToken: $accessToken,
            refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            expiresAt: self::deadline($payload['expires_in'] ?? null),
            refreshTokenExpiresAt: self::deadline($payload['refresh_token_expires_in'] ?? null),
        );
    }

    private static function deadline(mixed $seconds): ?CarbonImmutable
    {
        return is_numeric($seconds) && (int) $seconds > 0
            ? CarbonImmutable::now()->addSeconds((int) $seconds)
            : null;
    }
}
