<?php

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Database\Factories\TwoFactorChallengeFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('two-factor');
});

test('a new code is emailed and the old one stops working', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $challenge = TwoFactorChallenge::factory()->for($user)->resendable()->create();

    $response = $this->postJson(route('auth.login.resend'), ['challenge_id' => $challenge->id]);

    $response->assertOk()
        ->assertJsonPath('challengeId', $challenge->id)
        ->assertJsonStructure(['expiresAt', 'resendAvailableAt']);

    $fresh = $challenge->fresh();
    expect(Hash::check(TwoFactorChallengeFactory::CODE, $fresh?->code_hash ?? ''))->toBeFalse();

    Notification::assertSentTo($user, TwoFactorCodeNotification::class, function (TwoFactorCodeNotification $notification) use ($fresh): bool {
        return Hash::check($notification->code, $fresh?->code_hash ?? '');
    });
});

test('asking again too soon is 429 with a retry hint', function (): void {
    Notification::fake();
    config()->set('auth.two_factor.resend_seconds', 60);
    $challenge = TwoFactorChallenge::factory()->create(['last_sent_at' => now()]);

    $response = $this->postJson(route('auth.login.resend'), ['challenge_id' => $challenge->id]);

    $response->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['message', 'retryAfter']);

    expect($response->json('retryAfter'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    Notification::assertNothingSent();
});

test('a consumed or expired challenge cannot be refreshed', function (): void {
    Notification::fake();
    $consumed = TwoFactorChallenge::factory()->consumed()->resendable()->create();
    $expired = TwoFactorChallenge::factory()->expired()->create();

    $this->postJson(route('auth.login.resend'), ['challenge_id' => $consumed->id])->assertGone();
    $this->postJson(route('auth.login.resend'), ['challenge_id' => $expired->id])->assertGone();
    Notification::assertNothingSent();
});

test('a challenge burned by wrong codes cannot be refreshed either', function (): void {
    Notification::fake();
    config()->set('auth.two_factor.max_attempts', 3);
    $challenge = TwoFactorChallenge::factory()->resendable()->create(['attempts' => 3]);

    $this->postJson(route('auth.login.resend'), ['challenge_id' => $challenge->id])->assertGone();
    Notification::assertNothingSent();
});

test('resending keeps the attempt budget already spent', function (): void {
    Notification::fake();
    $challenge = TwoFactorChallenge::factory()->resendable()->create(['attempts' => 3]);

    $this->postJson(route('auth.login.resend'), ['challenge_id' => $challenge->id])->assertOk();

    expect($challenge->fresh()?->attempts)->toBe(3);
});
