<?php

namespace App\Auth\Adapters;

use App\Auth\Contracts\AuthenticationAdapter;
use App\Exceptions\Auth\AuthenticationFailedException;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Email + password authentication against the local users table.
 */
class PasswordAdapter implements AuthenticationAdapter
{
    public const DRIVER = 'password';

    public function __construct(private readonly Hasher $hasher) {}

    public function driver(): string
    {
        return self::DRIVER;
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     */
    public function authenticate(array $credentials): User
    {
        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! $this->hasher->check($credentials['password'], $user->password)) {
            throw AuthenticationFailedException::invalidCredentials();
        }

        return $user;
    }
}
