<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\Adapters\PasswordAdapter;
use App\Auth\AuthenticationManager;
use App\Auth\TokenIssuer;
use App\Auth\TwoFactor\ChallengeService;
use App\Auth\TwoFactor\TrustedDeviceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthSessionResource;
use App\Http\Resources\TwoFactorChallengeResource;
use Illuminate\Http\JsonResponse;

/**
 * Email + password sign-in. Other adapters (OAuth providers) get their own
 * entry points but end in the same TokenIssuer.
 *
 * With two-factor enabled the password only opens a challenge (202) unless the
 * request carries the token of a remembered device; the session is then issued
 * by TwoFactorVerifyController once the emailed code is confirmed.
 */
class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        AuthenticationManager $adapters,
        ChallengeService $challenges,
        TrustedDeviceService $devices,
        TokenIssuer $issuer,
    ): JsonResponse {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->safe()->only(['email', 'password']);

        $user = $adapters->adapter(PasswordAdapter::DRIVER)->authenticate($credentials);

        /** @var string|null $deviceToken */
        $deviceToken = $request->validated('device_token');

        if ($challenges->isEnabled() && ! $devices->isTrusted($user, $deviceToken)) {
            $challenge = $challenges->start($user, $request->ip());

            return (new TwoFactorChallengeResource($challenge))
                ->response()
                ->setStatusCode(202);
        }

        return (new AuthSessionResource($issuer->issue($user)))->response();
    }
}
