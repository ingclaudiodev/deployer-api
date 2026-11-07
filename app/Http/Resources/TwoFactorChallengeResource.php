<?php

namespace App\Http\Resources;

use App\Auth\TwoFactor\ChallengeService;
use App\Auth\TwoFactor\TrustedDeviceService;
use App\Models\TwoFactorChallenge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a client receives instead of a session when a second factor is pending:
 * the opaque handle for the next request and what it needs to render the step.
 * The email is masked because the client never proved it beyond the password.
 *
 * @mixin TwoFactorChallenge
 */
class TwoFactorChallengeResource extends JsonResource
{
    /**
     * @return array{challengeId: string, method: 'email', destination: string, codeLength: int, expiresAt: string, resendAvailableAt: string, trustDays: int}
     */
    public function toArray(Request $request): array
    {
        $service = app(ChallengeService::class);

        return [
            'challengeId' => $this->id,
            'method' => 'email',
            'destination' => self::maskEmail($this->user->email),
            'codeLength' => $service->codeLength(),
            'expiresAt' => $this->expires_at->toIso8601String(),
            'resendAvailableAt' => $service->resendAvailableAt($this->resource)->toIso8601String(),
            'trustDays' => app(TrustedDeviceService::class)->trustDays(),
        ];
    }

    /**
     * `claudio@acme.com` becomes `c***@acme.com`: enough to recognise the inbox, not to learn it.
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
