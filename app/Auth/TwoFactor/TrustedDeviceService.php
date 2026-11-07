<?php

namespace App\Auth\TwoFactor;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Remember this device": after a confirmed code the client may keep a random
 * token that, together with the password, skips the next challenges from that
 * device until it expires. It never replaces the password, only the code.
 */
class TrustedDeviceService
{
    public function __construct(private readonly int $trustDays) {}

    public function trustDays(): int
    {
        return $this->trustDays;
    }

    /**
     * Register the device and hand back the plain token, shown to the client once.
     */
    public function trust(User $user, ?string $userAgent = null, ?string $ipAddress = null): string
    {
        $plainToken = Str::random(64);

        $user->trustedDevices()->create([
            'token_hash' => TrustedDevice::hashToken($plainToken),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 500, ''),
            'ip_address' => $ipAddress,
            'last_used_at' => now(),
            'expires_at' => now()->addDays($this->trustDays),
        ]);

        return $plainToken;
    }

    /**
     * Whether the token identifies a live device of this user. Anything else
     * (missing, foreign, expired, revoked) is quietly `false`: the caller falls
     * back to the code, never to an error that would reveal why.
     */
    public function isTrusted(User $user, ?string $plainToken): bool
    {
        if ($plainToken === null || $plainToken === '') {
            return false;
        }

        $device = $user->trustedDevices()
            ->active()
            ->where('token_hash', TrustedDevice::hashToken($plainToken))
            ->first();

        if ($device === null) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /**
     * Forget every device, e.g. after a password reset: a new password must
     * drop every shortcut around the second factor.
     */
    public function revokeAll(User $user): void
    {
        $user->trustedDevices()->delete();
    }
}
