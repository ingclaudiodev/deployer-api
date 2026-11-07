<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\TokenIssuer;
use App\Auth\TwoFactor\ChallengeService;
use App\Auth\TwoFactor\TrustedDeviceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorVerifyRequest;
use App\Http\Resources\AuthSessionResource;

/**
 * Second step of the sign-in: the emailed code confirms the challenge opened by
 * LoginController or RegisterController and the session opens through the same
 * TokenIssuer. With `remember_device` the answer also carries a device token the
 * client keeps to skip the code next time.
 */
class TwoFactorVerifyController extends Controller
{
    public function __invoke(
        TwoFactorVerifyRequest $request,
        ChallengeService $challenges,
        TrustedDeviceService $devices,
        TokenIssuer $issuer,
    ): AuthSessionResource {
        /** @var array{challenge_id: string, code: string} $data */
        $data = $request->safe()->only(['challenge_id', 'code']);

        $user = $challenges->verify($data['challenge_id'], $data['code']);

        $issued = $issuer->issue($user);

        if ($request->boolean('remember_device')) {
            $issued = $issued->withDeviceToken($devices->trust($user, $request->userAgent(), $request->ip()));
        }

        return new AuthSessionResource($issued);
    }
}
