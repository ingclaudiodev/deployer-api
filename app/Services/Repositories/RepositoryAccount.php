<?php

namespace App\Services\Repositories;

/**
 * The account behind an access token, as its host identifies it.
 */
final readonly class RepositoryAccount
{
    public function __construct(
        public string $id,
        public string $login,
    ) {}
}
