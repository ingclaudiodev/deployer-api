<?php

namespace App\Exceptions\Repositories;

use App\Enums\RepositoryProvider;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * The access token of a repository connection could not be renewed. Wraps the
 * host's error so callers never depend on how GitHub or GitLab report it.
 */
class TokenRefreshException extends RuntimeException
{
    /**
     * Whether the user has to connect the account again: the host turned the
     * refresh token down, so retrying will not help.
     */
    public bool $requiresReconnection = false;

    /**
     * What the client is told: 409 with `reconectar` when the user has to connect
     * the account again, 502 when the host failed. The internal message is not sent.
     */
    public function render(): JsonResponse
    {
        return $this->requiresReconnection
            ? new JsonResponse(['message' => __('Connect your account again to continue.'), 'reconectar' => true], 409)
            : new JsonResponse(['message' => __('The repository host did not answer. Try again in a moment.'), 'reconectar' => false], 502);
    }

    public static function notConfigured(RepositoryProvider $provider): self
    {
        return new self("The {$provider->value} OAuth application is not configured: set its client id and secret.");
    }

    public static function unsupportedProvider(RepositoryProvider $provider): self
    {
        return new self("No token adapter is registered for {$provider->value}.");
    }

    public static function requestFailed(RepositoryProvider $provider, Throwable $previous): self
    {
        return new self("The {$provider->value} token request failed: {$previous->getMessage()}", previous: $previous);
    }

    public static function unexpectedAnswer(RepositoryProvider $provider, int $status): self
    {
        return new self("{$provider->value} answered the token request with HTTP {$status} and no access token.");
    }

    /**
     * The refresh token is expired, revoked or already used.
     */
    public static function rejected(RepositoryProvider $provider, string $error): self
    {
        $exception = new self("{$provider->value} rejected the refresh token ({$error}).");
        $exception->requiresReconnection = true;

        return $exception;
    }

    public static function missingRefreshToken(RepositoryProvider $provider): self
    {
        $exception = new self("The {$provider->value} access token expired and the connection has no refresh token.");
        $exception->requiresReconnection = true;

        return $exception;
    }
}
