<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\TokenIssuer;
use App\Auth\TwoFactor\ChallengeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthSessionResource;
use App\Http\Resources\TwoFactorChallengeResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

/**
 * Create an account from the sign-up form. With two-factor enabled the new account
 * gets the same emailed code as a sign-in (202), which also proves the address
 * before its first session; otherwise the session opens at once (201).
 */
class RegisterController extends Controller
{
    public function __invoke(
        RegisterRequest $request,
        ChallengeService $challenges,
        TokenIssuer $issuer,
    ): JsonResponse {
        /** @var array{name: string, email: string, password: string} $data */
        $data = $request->safe()->only(['name', 'email', 'password']);

        $user = User::query()->create($data);

        event(new Registered($user));

        if ($challenges->isEnabled()) {
            $challenge = $challenges->start($user, $request->ip());

            return (new TwoFactorChallengeResource($challenge))
                ->response()
                ->setStatusCode(202);
        }

        return (new AuthSessionResource($issuer->issue($user)))
            ->response()
            ->setStatusCode(201);
    }
}
