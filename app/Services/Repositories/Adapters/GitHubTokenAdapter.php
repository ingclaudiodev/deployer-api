<?php

namespace App\Services\Repositories\Adapters;

use App\Enums\RepositoryProvider;

class GitHubTokenAdapter extends OAuthTokenAdapter
{
    public function provider(): RepositoryProvider
    {
        return RepositoryProvider::GitHub;
    }

    protected function tokenUrl(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    protected function configKey(): string
    {
        return 'github';
    }
}
