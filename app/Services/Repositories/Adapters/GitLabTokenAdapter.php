<?php

namespace App\Services\Repositories\Adapters;

use App\Enums\RepositoryProvider;

class GitLabTokenAdapter extends OAuthTokenAdapter
{
    public function provider(): RepositoryProvider
    {
        return RepositoryProvider::GitLab;
    }

    /**
     * `services.gitlab.url` allows a self-managed GitLab instead of gitlab.com.
     */
    protected function tokenUrl(): string
    {
        return rtrim((string) config('services.gitlab.url'), '/').'/oauth/token';
    }

    protected function configKey(): string
    {
        return 'gitlab';
    }
}
