<?php

namespace App\Auth\Contracts;

use App\Auth\TokenIssuer;
use App\Exceptions\Auth\AuthenticationFailedException;
use App\Models\User;

/**
 * One way of proving who a user is: email/password today, GitHub or GitLab OAuth later.
 *
 * Every adapter resolves a credential payload to a local User. What happens next
 * (issuing a Passport token) is the same for all of them, see {@see TokenIssuer}.
 */
interface AuthenticationAdapter
{
    /**
     * The driver name used to register and resolve the adapter, e.g. "password".
     */
    public function driver(): string;

    /**
     * Resolve the user behind the given credentials.
     *
     * @param  array<string, mixed>  $credentials  Already validated by the calling Form Request.
     *
     * @throws AuthenticationFailedException when the credentials do not identify a user.
     */
    public function authenticate(array $credentials): User;
}
