<?php

namespace App\Http\Controllers\Api\Auth;

use App\Auth\TokenIssuer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthSessionResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

/**
 * Create an account from the sign-up form and open its first session.
 */
class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, TokenIssuer $issuer): JsonResponse
    {
        /** @var array{name: string, email: string, password: string} $data */
        $data = $request->safe()->only(['name', 'email', 'password']);

        $user = User::query()->create($data);

        event(new Registered($user));

        return (new AuthSessionResource($issuer->issue($user)))
            ->response()
            ->setStatusCode(201);
    }
}
