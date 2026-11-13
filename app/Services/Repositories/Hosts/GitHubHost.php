<?php

namespace App\Services\Repositories\Hosts;

use App\Enums\RepositoryProvider;
use App\Exceptions\Repositories\RepositoryHostException;
use App\Services\Repositories\Contracts\RepositoryHost;
use App\Services\Repositories\RepositoryAccount;
use App\Services\Repositories\RepositoryToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * GitHub through its OAuth application and REST API. Configuration is read on
 * each call, never kept: the instance lives as long as the Octane worker.
 */
class GitHubHost implements RepositoryHost
{
    private const string AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    private const string TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const string API_URL = 'https://api.github.com';

    /** Reading private repositories and creating branches both need `repo`. */
    private const string SCOPE = 'repo';

    private const int TIMEOUT_SECONDS = 10;

    private const int PAGE_SIZE = 100;

    public function provider(): RepositoryProvider
    {
        return RepositoryProvider::GitHub;
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->credentials()['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): RepositoryToken
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS)->post(self::TOKEN_URL, [
                ...$this->credentials(),
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);
        } catch (ConnectionException $exception) {
            throw RepositoryHostException::requestFailed($this->provider(), $exception);
        }

        // GitHub reports a refused code with HTTP 200 and an `error` key.
        $error = $response->json('error');

        if (is_string($error) && $error !== '') {
            throw RepositoryHostException::codeRejected($this->provider(), $error);
        }

        $token = $response->successful() ? RepositoryToken::fromOAuthPayload((array) $response->json()) : null;

        return $token ?? throw RepositoryHostException::unexpectedAnswer($this->provider(), $response->status());
    }

    public function account(string $accessToken): RepositoryAccount
    {
        $user = $this->get($accessToken, '/user')->json();

        if (! is_array($user) || ! isset($user['id'], $user['login'])) {
            throw RepositoryHostException::unexpectedAnswer($this->provider(), 200);
        }

        return new RepositoryAccount((string) $user['id'], (string) $user['login']);
    }

    public function repositories(string $accessToken, int $page = 1): array
    {
        $repositories = $this->get($accessToken, '/user/repos', [
            'per_page' => self::PAGE_SIZE,
            'page' => max(1, $page),
            'sort' => 'pushed',
            'affiliation' => 'owner,collaborator,organization_member',
        ])->json();

        $summaries = [];

        foreach (is_array($repositories) ? $repositories : [] as $repository) {
            if (is_array($repository) && is_string($repository['full_name'] ?? null)) {
                $summaries[] = [
                    'nombreCompleto' => $repository['full_name'],
                    'privado' => (bool) ($repository['private'] ?? false),
                    'ramaPorDefecto' => is_string($repository['default_branch'] ?? null) ? $repository['default_branch'] : 'main',
                ];
            }
        }

        return $summaries;
    }

    public function branches(string $accessToken, string $repository): array
    {
        $branches = $this->get($accessToken, "/repos/{$repository}/branches", ['per_page' => self::PAGE_SIZE])->json();

        $names = [];

        foreach (is_array($branches) ? $branches : [] as $branch) {
            if (is_array($branch) && is_string($branch['name'] ?? null)) {
                $names[] = $branch['name'];
            }
        }

        return $names;
    }

    public function repository(string $accessToken, string $repository): ?array
    {
        $found = $this->find($accessToken, "/repos/{$repository}")?->json();

        if (! is_array($found) || ! is_string($found['full_name'] ?? null)) {
            return null;
        }

        return [
            'nombreCompleto' => $found['full_name'],
            'privado' => (bool) ($found['private'] ?? false),
            'ramaPorDefecto' => is_string($found['default_branch'] ?? null) ? $found['default_branch'] : 'main',
        ];
    }

    public function tree(string $accessToken, string $repository, string $branch): array
    {
        $tree = $this->get($accessToken, "/repos/{$repository}/git/trees/{$this->head($accessToken, $repository, $branch)['tree']}", ['recursive' => 1])->json('tree');

        $paths = [];

        foreach (is_array($tree) ? $tree : [] as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'blob' && is_string($entry['path'] ?? null)) {
                $paths[] = $entry['path'];
            }
        }

        return $paths;
    }

    public function file(string $accessToken, string $repository, string $branch, string $path): ?string
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return $this->find($accessToken, "/repos/{$repository}/contents/{$encoded}", ['ref' => $branch], raw: true)?->body();
    }

    public function archiveUrl(string $accessToken, string $repository, string $branch): string
    {
        try {
            // GitHub answers with a redirect to a signed, short-lived link; the link is the answer.
            $response = Http::withToken($accessToken)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->withoutRedirecting()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(self::API_URL."/repos/{$repository}/tarball/{$branch}");
        } catch (ConnectionException $exception) {
            throw RepositoryHostException::requestFailed($this->provider(), $exception);
        }

        if ($response->status() === 401) {
            throw RepositoryHostException::unauthorized($this->provider());
        }

        $location = $response->header('Location');

        if ($response->status() !== 302 || $location === '') {
            throw RepositoryHostException::unexpectedAnswer($this->provider(), $response->status());
        }

        return $location;
    }

    public function publishBranch(string $accessToken, string $repository, string $baseBranch, string $branch, array $files, string $message): void
    {
        $base = $this->head($accessToken, $repository, $baseBranch);

        $tree = $this->write('POST', $accessToken, "/repos/{$repository}/git/trees", [
            'base_tree' => $base['tree'],
            'tree' => array_map(fn (array $file): array => [
                'path' => $file['path'],
                // Scripts keep their executable bit, so `deployer/deploy.sh` runs as it is.
                'mode' => str_ends_with($file['path'], '.sh') ? '100755' : '100644',
                'type' => 'blob',
                'content' => $file['content'],
            ], $files),
        ])->json('sha');

        $commit = $this->write('POST', $accessToken, "/repos/{$repository}/git/commits", [
            'message' => $message,
            'tree' => $tree,
            'parents' => [$base['commit']],
        ])->json('sha');

        $created = $this->send('POST', $accessToken, "/repos/{$repository}/git/refs", ['ref' => "refs/heads/{$branch}", 'sha' => $commit]);

        // 422: the branch is already there from an earlier publication.
        if ($created->status() === 422) {
            $this->write('PATCH', $accessToken, "/repos/{$repository}/git/refs/heads/{$branch}", ['sha' => $commit, 'force' => true]);
        } elseif (! $created->successful()) {
            throw RepositoryHostException::unexpectedAnswer($this->provider(), $created->status());
        }
    }

    /**
     * The commit a branch points at and the tree of that commit.
     *
     * @return array{commit: string, tree: string}
     */
    private function head(string $accessToken, string $repository, string $branch): array
    {
        $found = $this->get($accessToken, "/repos/{$repository}/branches/{$branch}")->json();
        $commit = is_array($found) ? ($found['commit']['sha'] ?? null) : null;
        $tree = is_array($found) ? ($found['commit']['commit']['tree']['sha'] ?? null) : null;

        if (! is_string($commit) || ! is_string($tree)) {
            throw RepositoryHostException::unexpectedAnswer($this->provider(), 200);
        }

        return ['commit' => $commit, 'tree' => $tree];
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function get(string $accessToken, string $path, array $query = []): Response
    {
        return $this->find($accessToken, $path, $query)
            ?? throw RepositoryHostException::unexpectedAnswer($this->provider(), 404);
    }

    /**
     * A read that may find nothing: `null` on 404.
     *
     * @param  array<string, scalar>  $query
     */
    private function find(string $accessToken, string $path, array $query = [], bool $raw = false): ?Response
    {
        $response = $this->send('GET', $accessToken, $path, $query, $raw);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw RepositoryHostException::unexpectedAnswer($this->provider(), $response->status());
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(string $method, string $accessToken, string $path, array $data): Response
    {
        $response = $this->send($method, $accessToken, $path, $data);

        if (! $response->successful()) {
            throw RepositoryHostException::unexpectedAnswer($this->provider(), $response->status());
        }

        return $response;
    }

    /**
     * One call to the REST API. Only a rejected token is judged here; the caller
     * decides what any other status means.
     *
     * @param  array<string, mixed>  $data  Query of a GET, JSON body of anything else.
     */
    private function send(string $method, string $accessToken, string $path, array $data = [], bool $raw = false): Response
    {
        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Accept' => $raw ? 'application/vnd.github.raw+json' : 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->send($method, self::API_URL.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException $exception) {
            throw RepositoryHostException::requestFailed($this->provider(), $exception);
        }

        if ($response->status() === 401) {
            throw RepositoryHostException::unauthorized($this->provider());
        }

        return $response;
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function credentials(): array
    {
        $clientId = config('services.github.client_id');
        $clientSecret = config('services.github.client_secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw RepositoryHostException::notConfigured($this->provider());
        }

        return ['client_id' => $clientId, 'client_secret' => $clientSecret];
    }
}
