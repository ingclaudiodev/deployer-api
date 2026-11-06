<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

/**
 * Email a reset link. The answer is the same whether or not the address has an
 * account, so the endpoint cannot be used to enumerate users.
 */
class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink($request->safe()->only(['email']));

        return new JsonResponse(['message' => trans('passwords.sent')]);
    }
}
