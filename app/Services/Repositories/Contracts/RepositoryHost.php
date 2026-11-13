<?php

namespace App\Services\Repositories\Contracts;

use App\Enums\RepositoryProvider;
use App\Exceptions\Repositories\RepositoryHostException;
use App\Services\Repositories\RepositoryAccount;
use App\Services\Repositories\RepositoryToken;

/**
 * What Deployer needs from a place that hosts repositories: letting a user
 * connect their account and reading what that account can reach. One
 * implementation per host; nothing outside it knows the host's API.
 *
 * @phpstan-type RepositorySummary array{nombreCompleto: string, privado: bool, ramaPorDefecto: string}
 */
interface RepositoryHost
{
    public function provider(): RepositoryProvider;

    /**
     * Where the browser goes for the user to authorize Deployer.
     */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * Trade the code of the callback for the tokens of the account.
     *
     * @throws RepositoryHostException
     */
    public function exchangeCode(string $code, string $redirectUri): RepositoryToken;

    /**
     * @throws RepositoryHostException
     */
    public function account(string $accessToken): RepositoryAccount;

    /**
     * Repositories the account can reach, most recently pushed first, one page at a time.
     *
     * @return list<RepositorySummary>
     *
     * @throws RepositoryHostException
     */
    public function repositories(string $accessToken, int $page = 1): array;

    /**
     * Branch names of a repository (`owner/name`).
     *
     * @return list<string>
     *
     * @throws RepositoryHostException
     */
    public function branches(string $accessToken, string $repository): array;

    /**
     * One repository (`owner/name`) as the account sees it; `null` when the
     * account cannot reach it or it does not exist.
     *
     * @return RepositorySummary|null
     *
     * @throws RepositoryHostException
     */
    public function repository(string $accessToken, string $repository): ?array;

    /**
     * Paths of every file of a branch, relative to the root of the repository.
     *
     * @return list<string>
     *
     * @throws RepositoryHostException
     */
    public function tree(string $accessToken, string $repository, string $branch): array;

    /**
     * Content of one file of a branch; `null` when it is not there.
     *
     * @throws RepositoryHostException
     */
    public function file(string $accessToken, string $repository, string $branch, string $path): ?string;

    /**
     * A link to download the branch as a `.tar.gz`, good for a few minutes and
     * for that archive only, so whoever builds the project never holds the
     * token of the account.
     *
     * @throws RepositoryHostException
     */
    public function archiveUrl(string $accessToken, string $repository, string $branch): string;

    /**
     * Leave `branch` one commit ahead of `baseBranch`, the commit adding or
     * replacing the given files. A branch that already exists is moved there:
     * it belongs to Deployer, never to the user.
     *
     * @param  list<array{path: string, content: string}>  $files
     *
     * @throws RepositoryHostException
     */
    public function publishBranch(string $accessToken, string $repository, string $baseBranch, string $branch, array $files, string $message): void;
}
