<?php

namespace App\Exceptions\Repositories;

use App\Enums\RepositoryProvider;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * A repository host (GitHub, GitLab) could not do what was asked of it. Wraps
 * the host's error so callers never depend on how each one reports it.
 */
class RepositoryHostException extends RuntimeException
{
    /**
     * Whether the user has to connect the account again before retrying.
     */
    public bool $requiresReconnection = false;

    /**
     * Whether Deployer cannot connect accounts of this host at all (yet).
     */
    public bool $unavailable = false;

    public static function notConfigured(RepositoryProvider $provider): self
    {
        return new self("The {$provider->value} OAuth application is not configured: set its client id and secret.");
    }

    public static function unsupported(RepositoryProvider $provider): self
    {
        $exception = new self("Connecting {$provider->value} accounts is not available yet.");
        $exception->unavailable = true;

        return $exception;
    }

    public static function requestFailed(RepositoryProvider $provider, Throwable $previous): self
    {
        return new self("The {$provider->value} request failed: {$previous->getMessage()}", previous: $previous);
    }

    public static function unexpectedAnswer(RepositoryProvider $provider, int $status): self
    {
        return new self("{$provider->value} answered with HTTP {$status}.");
    }

    /**
     * The code of the OAuth callback was refused: expired, already used or made up.
     */
    public static function codeRejected(RepositoryProvider $provider, string $error): self
    {
        return new self("{$provider->value} rejected the authorization code ({$error}).");
    }

    /**
     * The callback came back with a state this application did not hand out, or
     * one that already expired.
     */
    public static function unknownState(RepositoryProvider $provider): self
    {
        return new self("The {$provider->value} authorization does not match a pending connection.");
    }

    /**
     * The host no longer accepts the access token: the user revoked the application.
     */
    public static function unauthorized(RepositoryProvider $provider): self
    {
        $exception = new self("{$provider->value} no longer accepts the access token of the connection.");
        $exception->requiresReconnection = true;

        return $exception;
    }

    /**
     * The stored tokens cannot be decrypted with this installation's key.
     */
    public static function unreadableTokens(RepositoryProvider $provider): self
    {
        $exception = new self("The stored {$provider->value} tokens cannot be read with this application key.");
        $exception->requiresReconnection = true;

        return $exception;
    }

    public static function notConnected(RepositoryProvider $provider): self
    {
        $exception = new self("The user has no {$provider->value} account connected.");
        $exception->requiresReconnection = true;

        return $exception;
    }

    /**
     * Same contract as {@see TokenRefreshException::render()}.
     */
    public function render(): JsonResponse
    {
        if ($this->unavailable) {
            return new JsonResponse(['message' => __('This repository host is not available yet.'), 'reconectar' => false], 404);
        }

        return $this->requiresReconnection
            ? new JsonResponse(['message' => __('Connect your account again to continue.'), 'reconectar' => true], 409)
            : new JsonResponse(['message' => __('The repository host did not answer. Try again in a moment.'), 'reconectar' => false], 502);
    }
}
