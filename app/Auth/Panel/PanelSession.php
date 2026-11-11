<?php

namespace App\Auth\Panel;

use App\Auth\TokenIssuer;
use App\Auth\TwoFactor\ChallengeService;
use App\Auth\TwoFactor\TrustedDeviceService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The browser side of a sign-in to the operations panel. The API ends a sign-in
 * with a Passport token ({@see TokenIssuer}); the panel ends the same
 * two steps (password, then the emailed code of {@see ChallengeService}) with a
 * session of the `web` guard, which is also what Horizon's gate reads.
 */
class PanelSession
{
    /**
     * Session key that holds the id of the challenge opened by the password step.
     */
    public const string PENDING_CHALLENGE = 'panel.pending_challenge';

    /**
     * Cookie with the plain token of a remembered device ({@see TrustedDeviceService}).
     */
    public const string DEVICE_COOKIE = 'deployer_panel_device';

    private const string GUARD = 'web';

    public function __construct(private readonly TrustedDeviceService $devices) {}

    /**
     * Only super administrators operate the panel: `auth.default_role` makes every
     * sign-up an admin, so a weaker rule would open it to any registered account.
     */
    public function mayEnter(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Open the session. The id is regenerated so one fixed before the sign-in is useless.
     */
    public function open(Request $request, User $user): void
    {
        Auth::guard(self::GUARD)->login($user);

        $request->session()->forget(self::PENDING_CHALLENGE);
        $request->session()->regenerate();
    }

    public function close(Request $request): void
    {
        Auth::guard(self::GUARD)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function deviceToken(Request $request): ?string
    {
        $token = $request->cookie(self::DEVICE_COOKIE);

        return is_string($token) ? $token : null;
    }

    /**
     * Remember this browser: the cookie carries the plain token, readable only by
     * the server (HttpOnly) and only sent to the panel's own paths.
     */
    public function rememberDevice(Request $request, User $user): Cookie
    {
        $token = $this->devices->trust($user, $request->userAgent(), $request->ip());

        return cookie(
            name: self::DEVICE_COOKIE,
            value: $token,
            minutes: $this->devices->trustDays() * 24 * 60,
            path: '/panel',
            httpOnly: true,
            sameSite: 'lax',
        );
    }
}
