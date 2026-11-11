<?php

namespace App\Http\Controllers\Panel;

use App\Auth\Panel\PanelSession;
use App\Auth\TwoFactor\ChallengeService;
use App\Auth\TwoFactor\TrustedDeviceService;
use App\Exceptions\Auth\TwoFactorChallengeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\VerifyCodeRequest;
use App\Http\Resources\TwoFactorChallengeResource;
use App\Models\TwoFactorChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Code step of the panel sign-in. The challenge id never travels to the browser:
 * it stays in the session, so the code can only be tried from the browser that
 * passed the password step.
 */
class LoginCodeController extends Controller
{
    public function __construct(
        private readonly ChallengeService $challenges,
        private readonly PanelSession $panel,
    ) {}

    public function show(Request $request, TrustedDeviceService $devices): Response|RedirectResponse
    {
        $challenge = TwoFactorChallenge::query()
            ->pending()
            ->with('user')
            ->find($this->pendingChallengeId($request));

        if ($challenge === null) {
            return $this->backToPassword($request);
        }

        return Inertia::render('panel/LoginCode', [
            'destination' => TwoFactorChallengeResource::maskEmail($challenge->user->email),
            'codeLength' => $this->challenges->codeLength(),
            'expiresAt' => $challenge->expires_at->toIso8601String(),
            'resendAvailableAt' => $this->challenges->resendAvailableAt($challenge)->toIso8601String(),
            'trustDays' => $devices->trustDays(),
        ]);
    }

    public function store(VerifyCodeRequest $request): RedirectResponse
    {
        try {
            $user = $this->challenges->verify(
                (string) $this->pendingChallengeId($request),
                $request->safe()->string('code')->toString(),
            );
        } catch (TwoFactorChallengeException $exception) {
            return $this->refuse($request, $exception);
        }

        // The role may have changed between the two steps.
        if (! $this->panel->mayEnter($user)) {
            return $this->backToPassword($request);
        }

        $this->panel->open($request, $user);

        $response = redirect()->intended(route('panel.dashboard'));

        return $request->boolean('remember_device')
            ? $response->withCookie($this->panel->rememberDevice($request, $user))
            : $response;
    }

    public function resend(Request $request): RedirectResponse
    {
        try {
            $this->challenges->resend((string) $this->pendingChallengeId($request));
        } catch (TwoFactorChallengeException $exception) {
            return $this->refuse($request, $exception);
        }

        return to_route('panel.login.code');
    }

    private function pendingChallengeId(Request $request): ?string
    {
        $id = $request->session()->get(PanelSession::PENDING_CHALLENGE);

        return is_string($id) ? $id : null;
    }

    /**
     * 410 means the challenge is over (used, expired or burned): only the password
     * step can open another. Anything else is shown next to the code field.
     */
    private function refuse(Request $request, TwoFactorChallengeException $exception): RedirectResponse
    {
        if ($exception->status() === 410) {
            return $this->backToPassword($request, $exception->getMessage());
        }

        throw ValidationException::withMessages(['code' => $exception->getMessage()]);
    }

    private function backToPassword(Request $request, ?string $reason = null): RedirectResponse
    {
        $request->session()->forget(PanelSession::PENDING_CHALLENGE);

        $redirect = to_route('panel.login');

        return $reason === null ? $redirect : $redirect->withErrors(['email' => $reason]);
    }
}
