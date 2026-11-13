<?php

namespace App\Services\Repositories\Contracts;

use App\Enums\RepositoryProvider;
use App\Exceptions\Repositories\TokenRefreshException;
use App\Services\Repositories\RefreshTokenService;
use App\Services\Repositories\RepositoryToken;

/**
 * How one repository host (GitHub, GitLab) renews an access token. The only
 * place that knows the host's URL and the shape of its answer:
 * {@see RefreshTokenService} works with any adapter without telling them apart.
 */
interface RepositoryTokenAdapter
{
    /**
     * The host this adapter talks to; the service resolves adapters by it.
     */
    public function provider(): RepositoryProvider;

    /**
     * Exchange a refresh token for a new pair. Hosts rotate the refresh token:
     * the one passed in stops working once this returns.
     *
     * @throws TokenRefreshException when the host is not configured, cannot be reached or refuses the token.
     */
    public function refresh(string $refreshToken): RepositoryToken;
}
