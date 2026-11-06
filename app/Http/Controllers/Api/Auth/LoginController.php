<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\Adapters\PasswordAdapter;
use App\Auth\AuthenticationManager;
use App\Auth\TokenIssuer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthSessionResource;

/**
 * Email + password sign-in. Other adapters (OAuth providers) get their own
 * entry points but end in the same TokenIssuer.
 */
class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        AuthenticationManager $adapters,
        TokenIssuer $issuer,
    ): AuthSessionResource {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->safe()->only(['email', 'password']);

        $user = $adapters->adapter(PasswordAdapter::DRIVER)->authenticate($credentials);

        return new AuthSessionResource($issuer->issue($user));
    }
}
