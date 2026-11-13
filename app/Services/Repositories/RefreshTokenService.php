<?php

namespace App\Services\Repositories;

use App\Enums\RepositoryProvider;
use App\Exceptions\Repositories\TokenRefreshException;
use App\Models\RepositoryConnection;
use App\Services\Repositories\Contracts\RepositoryTokenAdapter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the access token of a repository connection usable. Whoever calls
 * GitHub or GitLab asks here for the token and never looks at its expiry.
 *
 * The service does not know the hosts: each one is a {@see RepositoryTokenAdapter},
 * picked by the provider of the connection.
 */
class RefreshTokenService
{
    /** Renew this long before the deadline, so a token never expires mid-request. */
    private const int EXPIRY_MARGIN_SECONDS = 300;

    private const int LOCK_SECONDS = 30;

    private const int LOCK_WAIT_SECONDS = 15;

    /** @var array<string, RepositoryTokenAdapter> */
    private array $adapters = [];

    /**
     * @param  iterable<RepositoryTokenAdapter>  $adapters
     */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->provider()->value] = $adapter;
        }
    }

    /**
     * An access token of the connection that is good to use now, renewed first
     * when it is about to expire.
     *
     * @throws TokenRefreshException
     */
    public function accessToken(RepositoryConnection $connection): string
    {
        return $this->expiresSoon($connection) ? $this->refresh($connection)->access_token : $connection->access_token;
    }

    /**
     * Renew the tokens of the connection and store them.
     *
     * Hosts accept each refresh token once. Two requests renewing at the same
     * time would leave the loser with a dead token, so only one renews and the
     * other reads what it stored.
     *
     * @throws TokenRefreshException
     */
    public function refresh(RepositoryConnection $connection): RepositoryConnection
    {
        $lock = Cache::lock("repository-connection:{$connection->id}:refresh", self::LOCK_SECONDS);

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($connection): RepositoryConnection {
                $connection->refresh();

                if (! $this->expiresSoon($connection)) {
                    return $connection;
                }

                if ($connection->refresh_token === null) {
                    throw TokenRefreshException::missingRefreshToken($connection->provider);
                }

                $token = $this->adapter($connection->provider)->refresh($connection->refresh_token);

                $connection->forceFill([
                    'access_token' => $token->accessToken,
                    'refresh_token' => $token->refreshToken ?? $connection->refresh_token,
                    'access_token_expires_at' => $token->expiresAt,
                    'refresh_token_expires_at' => $token->refreshTokenExpiresAt ?? $connection->refresh_token_expires_at,
                ])->save();

                return $connection;
            });
        } catch (LockTimeoutException $exception) {
            throw TokenRefreshException::requestFailed($connection->provider, $exception);
        }
    }

    private function expiresSoon(RepositoryConnection $connection): bool
    {
        return $connection->access_token_expires_at !== null
            && $connection->access_token_expires_at->lte(now()->addSeconds(self::EXPIRY_MARGIN_SECONDS));
    }

    private function adapter(RepositoryProvider $provider): RepositoryTokenAdapter
    {
        return $this->adapters[$provider->value] ?? throw TokenRefreshException::unsupportedProvider($provider);
    }
}
