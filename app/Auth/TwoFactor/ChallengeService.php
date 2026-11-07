<?php

namespace App\Auth\TwoFactor;

use App\Auth\Contracts\AuthenticationAdapter;
use App\Auth\TokenIssuer;
use App\Exceptions\Auth\TwoFactorChallengeException;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * The second step of a sign-in: a one-time numeric code emailed after the password
 * was accepted. Sits between any {@see AuthenticationAdapter}
 * and the {@see TokenIssuer}, so every way of proving identity gets the
 * same gate. Policy numbers come from `config('auth.two_factor')`.
 *
 * @phpstan-type Settings array{enabled: bool, code_length: int, expire_minutes: int, max_attempts: int, resend_seconds: int}
 */
class ChallengeService
{
    /**
     * @param  Settings  $settings
     */
    public function __construct(
        private readonly Hasher $hasher,
        private readonly array $settings,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings['enabled'];
    }

    public function codeLength(): int
    {
        return $this->settings['code_length'];
    }

    /**
     * Open a challenge for a user whose first factor already passed, and email the code.
     */
    public function start(User $user, ?string $ipAddress = null): TwoFactorChallenge
    {
        $code = $this->generateCode();

        $challenge = $user->twoFactorChallenges()->create([
            'code_hash' => $this->hasher->make($code),
            'attempts' => 0,
            'ip_address' => $ipAddress,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes($this->settings['expire_minutes']),
        ]);

        $user->notify(new TwoFactorCodeNotification($code, $this->settings['expire_minutes']));

        return $challenge;
    }

    /**
     * Confirm the code. On success the challenge is consumed and cannot be replayed,
     * and the user's email counts as verified.
     *
     * @throws TwoFactorChallengeException when the challenge is gone, burned or the code is wrong.
     */
    public function verify(string $challengeId, string $code): User
    {
        $challenge = $this->findPending($challengeId);

        if (! $this->hasher->check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');
            $attemptsLeft = $this->settings['max_attempts'] - $challenge->attempts;

            if ($attemptsLeft <= 0) {
                throw TwoFactorChallengeException::tooManyAttempts();
            }

            throw TwoFactorChallengeException::invalidCode($attemptsLeft);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();

        $user = $challenge->user;

        // The code only ever reaches the account's inbox, so confirming it proves the address.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
            event(new Verified($user));
        }

        return $user;
    }

    /**
     * Email a fresh code for the same challenge. The attempt budget is not reset:
     * it belongs to the sign-in, not to the code, so guessing stays bounded.
     *
     * @throws TwoFactorChallengeException when the challenge is gone or the last email is too recent.
     */
    public function resend(string $challengeId): TwoFactorChallenge
    {
        $challenge = $this->findPending($challengeId);

        $availableAt = $this->resendAvailableAt($challenge);

        if ($availableAt->isFuture()) {
            throw TwoFactorChallengeException::resendTooSoon(
                max(1, (int) ceil(now()->diffInSeconds($availableAt, absolute: true))),
            );
        }

        $code = $this->generateCode();

        $challenge->forceFill([
            'code_hash' => $this->hasher->make($code),
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes($this->settings['expire_minutes']),
        ])->save();

        $challenge->user->notify(new TwoFactorCodeNotification($code, $this->settings['expire_minutes']));

        return $challenge;
    }

    /**
     * Earliest moment another email may be requested for this challenge.
     */
    public function resendAvailableAt(TwoFactorChallenge $challenge): CarbonImmutable
    {
        return CarbonImmutable::instance($challenge->last_sent_at)
            ->addSeconds($this->settings['resend_seconds']);
    }

    /**
     * @throws TwoFactorChallengeException
     */
    private function findPending(string $challengeId): TwoFactorChallenge
    {
        $challenge = TwoFactorChallenge::query()->whereKey($challengeId)->first();

        if ($challenge === null || $challenge->isConsumed() || $challenge->isExpired()) {
            throw TwoFactorChallengeException::challengeGone();
        }

        // Burned by wrong codes: neither a verify nor a resend may revive it.
        if ($challenge->attempts >= $this->settings['max_attempts']) {
            throw TwoFactorChallengeException::tooManyAttempts();
        }

        return $challenge;
    }

    /**
     * Zero-padded so every code has the configured length, leading zeros included.
     */
    private function generateCode(): string
    {
        $length = $this->settings['code_length'];

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
