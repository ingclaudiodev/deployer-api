<?php

namespace App\Http\Controllers\Panel;

use App\Auth\Adapters\PasswordAdapter;
use App\Auth\AuthenticationManager;
use App\Auth\Panel\PanelSession;
use App\Auth\TwoFactor\ChallengeService;
use App\Auth\TwoFactor\TrustedDeviceService;
use App\Exceptions\Auth\AuthenticationFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password step of the panel sign-in. Same adapter and same second factor as the
 * API ({@see \App\Http\Controllers\Api\Auth\LoginController}); only the outcome
 * differs: a browser session instead of a token.
 */
class LoginController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('panel/Login');
    }

    public function store(
        LoginRequest $request,
        AuthenticationManager $adapters,
        ChallengeService $challenges,
        TrustedDeviceService $devices,
        PanelSession $panel,
    ): RedirectResponse {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->safe()->only(['email', 'password']);

        try {
            $user = $adapters->adapter(PasswordAdapter::DRIVER)->authenticate($credentials);
        } catch (AuthenticationFailedException) {
            $user = null;
        }

        // One answer for "wrong password" and "not a super administrator": the form
        // must not tell which accounts exist nor which of them may operate the panel.
        if ($user === null || ! $panel->mayEnter($user)) {
            throw ValidationException::withMessages([
                'email' => __('The email or password is incorrect.'),
            ]);
        }

        if ($challenges->isEnabled() && ! $devices->isTrusted($user, $panel->deviceToken($request))) {
            $challenge = $challenges->start($user, $request->ip());

            $request->session()->put(PanelSession::PENDING_CHALLENGE, $challenge->id);

            return to_route('panel.login.code');
        }

        $panel->open($request, $user);

        return redirect()->intended(route('panel.dashboard'));
    }
}
