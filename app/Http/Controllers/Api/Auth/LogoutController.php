<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;

/**
 * Revoke the token used on this request; other devices keep their sessions.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();

        if ($token instanceof AccessToken) {
            $token->revoke();
        }

        return response()->noContent();
    }
}
