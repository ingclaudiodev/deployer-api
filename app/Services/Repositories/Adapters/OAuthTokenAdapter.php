<?php

namespace App\Services\Repositories\Adapters;

use App\Exceptions\Repositories\TokenRefreshException;
use App\Services\Repositories\Contracts\RepositoryTokenAdapter;
use App\Services\Repositories\RepositoryToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The `refresh_token` grant of OAuth 2, which GitHub and GitLab both speak. A
 * host only says where its token endpoint is and which credentials it uses.
 *
 * Configuration is read on each call, never kept: the adapter lives as long as
 * the Octane worker.
 */
abstract class OAuthTokenAdapter implements RepositoryTokenAdapter
{
    private const int TIMEOUT_SECONDS = 10;

    /**
     * Where the host exchanges tokens.
     */
    abstract protected function tokenUrl(): string;

    /**
     * Key of `config/services.php` holding `client_id` and `client_secret`.
     */
    abstract protected function configKey(): string;

    public function refresh(string $refreshToken): RepositoryToken
    {
        $clientId = config("services.{$this->configKey()}.client_id");
        $clientSecret = config("services.{$this->configKey()}.client_secret");

        if (blank($clientId) || blank($clientSecret)) {
            throw TokenRefreshException::notConfigured($this->provider());
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS)->post($this->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);
        } catch (ConnectionException $exception) {
            throw TokenRefreshException::requestFailed($this->provider(), $exception);
        }

        // GitHub reports a refused token with HTTP 200 and an `error` key; GitLab with HTTP 400.
        $error = $response->json('error');

        if (is_string($error) && $error !== '') {
            throw TokenRefreshException::rejected($this->provider(), $error);
        }

        $token = $response->successful() ? RepositoryToken::fromOAuthPayload((array) $response->json()) : null;

        return $token ?? throw TokenRefreshException::unexpectedAnswer($this->provider(), $response->status());
    }
}
