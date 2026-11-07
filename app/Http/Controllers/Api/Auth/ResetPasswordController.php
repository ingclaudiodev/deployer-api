<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\TwoFactor\TrustedDeviceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Set a new password from the emailed token, close every open session and forget
 * every remembered device: a new password must drop every shortcut around the code.
 */
class ResetPasswordController extends Controller
{
    public function __invoke(ResetPasswordRequest $request, TrustedDeviceService $devices): JsonResponse
    {
        /** @var array{token: string, email: string, password: string, password_confirmation: string} $credentials */
        $credentials = $request->safe()->only(['token', 'email', 'password', 'password_confirmation']);

        $status = Password::broker()->reset($credentials, function (User $user, string $password) use ($devices): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->update(['revoked' => true]);
            $devices->revokeAll($user);

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [trans($status)]]);
        }

        return new JsonResponse(['message' => trans($status)]);
    }
}
