<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Set a new password from the emailed token and close every open session.
 */
class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        /** @var array{token: string, email: string, password: string, password_confirmation: string} $credentials */
        $credentials = $request->safe()->only(['token', 'email', 'password', 'password_confirmation']);

        $status = Password::broker()->reset($credentials, function (User $user, string $password): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->update(['revoked' => true]);

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [trans($status)]]);
        }

        return new JsonResponse(['message' => trans($status)]);
    }
}
