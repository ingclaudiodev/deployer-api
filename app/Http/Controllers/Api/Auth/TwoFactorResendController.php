<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\TwoFactor\ChallengeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorResendRequest;
use App\Http\Resources\TwoFactorChallengeResource;

/**
 * Email a new code for a pending challenge, e.g. when the first one never arrived.
 */
class TwoFactorResendController extends Controller
{
    public function __invoke(
        TwoFactorResendRequest $request,
        ChallengeService $challenges,
    ): TwoFactorChallengeResource {
        /** @var string $challengeId */
        $challengeId = $request->safe()->string('challenge_id')->toString();

        return new TwoFactorChallengeResource($challenges->resend($challengeId));
    }
}
