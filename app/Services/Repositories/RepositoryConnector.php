<?php

namespace App\Services\Repositories;

use App\Enums\RepositoryProvider;
use App\Exceptions\Repositories\RepositoryHostException;
use App\Exceptions\Repositories\TokenRefreshException;
use App\Models\RepositoryConnection;
use App\Models\User;
use App\Services\Repositories\Contracts\RepositoryHost;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Connects a user's account at a repository host and reads it on their behalf.
 * It picks the {@see RepositoryHost} by provider and never knows which one it got.
 *
 * @phpstan-import-type RepositorySummary from RepositoryHost
 */
class RepositoryConnector
{
    /** How long the user has to come back from the host's authorization page. */
    private const int STATE_MINUTES = 10;

    /** @var array<string, RepositoryHost> */
    private array $hosts = [];

    /**
     * @param  iterable<RepositoryHost>  $hosts
     */
    public function __construct(
        iterable $hosts,
        private readonly RefreshTokenService $tokens,
    ) {
        foreach ($hosts as $host) {
            $this->hosts[$host->provider()->value] = $host;
        }
    }

    /**
     * Where to send the browser so the user authorizes Deployer. The callback
     * arrives without the user's bearer token, so the state it carries back is
     * what says whose connection this is.
     *
     * @throws RepositoryHostException
     */
    public function authorizationUrl(User $user, RepositoryProvider $provider, string $redirectUri): string
    {
        $host = $this->host($provider);
        $state = Str::random(40);

        Cache::put($this->stateKey($provider, $state), $user->id, now()->addMinutes(self::STATE_MINUTES));

        return $host->authorizationUrl($state, $redirectUri);
    }

    /**
     * Finish the connection the callback belongs to. A state is good once.
     *
     * @throws RepositoryHostException
     */
    public function complete(RepositoryProvider $provider, string $code, string $state, string $redirectUri): RepositoryConnection
    {
        $host = $this->host($provider);
        $userId = Cache::pull($this->stateKey($provider, $state));
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user === null) {
            throw RepositoryHostException::unknownState($provider);
        }

        $token = $host->exchangeCode($code, $redirectUri);
        $account = $host->account($token->accessToken);

        $connection = $user->repositoryConnections()->firstOrNew(['provider' => $provider]);
        $connection->forceFill([
            'account_id' => $account->id,
            'account_login' => $account->login,
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'access_token_expires_at' => $token->expiresAt,
            'refresh_token_expires_at' => $token->refreshTokenExpiresAt,
        ])->save();

        return $connection;
    }

    /**
     * @return list<RepositorySummary>
     *
     * @throws RepositoryHostException|TokenRefreshException
     */
    public function repositories(User $user, RepositoryProvider $provider, int $page = 1): array
    {
        return $this->host($provider)->repositories($this->accessToken($user, $provider), $page);
    }

    /**
     * @return list<string>
     *
     * @throws RepositoryHostException|TokenRefreshException
     */
    public function branches(User $user, RepositoryProvider $provider, string $repository): array
    {
        return $this->host($provider)->branches($this->accessToken($user, $provider), $repository);
    }

    /**
     * Whether the user connected an account at this host.
     */
    public function connected(User $user, RepositoryProvider $provider): bool
    {
        return $user->repositoryConnections()->where('provider', $provider)->exists();
    }

    /**
     * @return RepositorySummary|null `null` when the account cannot reach the repository.
     *
     * @throws RepositoryHostException|TokenRefreshException
     */
    public function repository(User $user, RepositoryProvider $provider, string $repository): ?array
    {
        return $this->host($provider)->repository($this->accessToken($user, $provider), $repository);
    }

    /**
     * @return list<string>
     *
     * @throws RepositoryHostException|TokenRefreshException
     */
    public function tree(User $user, RepositoryProvider $provider, string $repository, string $branch): array
    {
        return $this->host($provider)->tree($this->accessToken($user, $provider), $repository, $branch);
    }

    /**
     * @throws RepositoryHostException|TokenRefreshException
     */
    public function file(User $user, RepositoryProvider $provider, string $repository, string $branch, string $path): ?string
    {
        return $this->host($provider)->file($this->accessToken($user, $provider), $repository, $branch, $path);
    }

    /**
     * @throws RepositoryHostException|TokenRefreshException
     */
    public function archiveUrl(User $user, RepositoryProvider $provider, string $repository, string $branch): string
    {
        return $this->host($provider)->archiveUrl($this->accessToken($user, $provider), $repository, $branch);
    }

    /**
     * @param  list<array{path: string, content: string}>  $files
     *
     * @throws RepositoryHostException|TokenRefreshException
     */
    public function publishBranch(User $user, RepositoryProvider $provider, string $repository, string $baseBranch, string $branch, array $files, string $message): void
    {
        $this->host($provider)->publishBranch($this->accessToken($user, $provider), $repository, $baseBranch, $branch, $files, $message);
    }

    private function accessToken(User $user, RepositoryProvider $provider): string
    {
        $connection = $user->repositoryConnections()->where('provider', $provider)->first();

        if ($connection === null) {
            throw RepositoryHostException::notConnected($provider);
        }

        try {
            return $this->tokens->accessToken($connection);
        } catch (DecryptException) {
            // The tokens were stored by an installation with another application key (the
            // database is shared between environments): here they are unreadable, and the
            // way out is the same as for a revoked token, connecting the account again.
            throw RepositoryHostException::unreadableTokens($provider);
        }
    }

    private function host(RepositoryProvider $provider): RepositoryHost
    {
        return $this->hosts[$provider->value] ?? throw RepositoryHostException::unsupported($provider);
    }

    private function stateKey(RepositoryProvider $provider, string $state): string
    {
        return "repository-oauth:{$provider->value}:{$state}";
    }
}
