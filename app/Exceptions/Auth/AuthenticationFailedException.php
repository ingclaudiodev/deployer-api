<?php

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The supplied credentials do not identify a user. Rendered as a 401 JSON body
 * so API clients can tell "wrong credentials" apart from validation (422) and server (5xx) errors.
 */
class AuthenticationFailedException extends Exception
{
    public static function invalidCredentials(): self
    {
        return new self(trans('auth.failed'));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 401);
    }
}
