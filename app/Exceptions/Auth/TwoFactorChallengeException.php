<?php

namespace App\Exceptions\Auth;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Why a second-factor request could not be honoured. Each case has its own HTTP
 * status so the client can react without parsing messages:
 *
 * - 401: the code is wrong, the challenge is still open (`attemptsLeft` says for how long).
 * - 410: the challenge is gone (unknown, used, expired or burned by too many attempts); sign in again.
 * - 429: a new code was asked too soon (`retryAfter` seconds).
 */
class TwoFactorChallengeException extends Exception
{
    /**
     * @param  array<string, int>  $extra
     */
    private function __construct(
        string $message,
        private readonly int $status,
        private readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidCode(int $attemptsLeft): self
    {
        return new self(__('The code is not valid.'), 401, ['attemptsLeft' => $attemptsLeft]);
    }

    public static function challengeGone(): self
    {
        return new self(__('This sign-in attempt is no longer valid. Please sign in again.'), 410);
    }

    public static function tooManyAttempts(): self
    {
        return new self(__('Too many wrong codes. Please sign in again.'), 410);
    }

    public static function resendTooSoon(int $retryAfter): self
    {
        return new self(__('A code was sent recently. Please wait before asking for another.'), 429, ['retryAfter' => $retryAfter]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        $response = new JsonResponse(['message' => $this->getMessage(), ...$this->extra], $this->status);

        if (isset($this->extra['retryAfter'])) {
            $response->header('Retry-After', (string) $this->extra['retryAfter']);
        }

        return $response;
    }
}
