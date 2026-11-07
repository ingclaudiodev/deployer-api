<?php

use App\Models\TrustedDevice;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Database\Factories\TwoFactorChallengeFactory;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    createPersonalAccessClient();
    RateLimiter::clear('two-factor');
});

test('the right code consumes the challenge and opens the session', function (): void {
    $user = User::factory()->create(['email' => 'dev@acme.com']);
    $challenge = TwoFactorChallenge::factory()->for($user)->create();

    $response = $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => TwoFactorChallengeFactory::CODE,
    ]);

    $response->assertOk()
        ->assertJsonPath('tokenType', 'Bearer')
        ->assertJsonPath('user.id', $user->id);

    expect($response->json('accessToken'))->toBeString()->not->toBeEmpty();
    expect($challenge->fresh()?->consumed_at)->not->toBeNull();

    $this->withToken($response->json('accessToken'))
        ->getJson(route('auth.me'))
        ->assertOk()
        ->assertJsonPath('email', 'dev@acme.com');
});

test('remembering the device returns a token that is stored hashed', function (): void {
    $user = User::factory()->create();
    $challenge = TwoFactorChallenge::factory()->for($user)->create();

    $response = $this->withHeader('User-Agent', 'PestBrowser/1.0')->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => TwoFactorChallengeFactory::CODE,
        'remember_device' => true,
    ]);

    $response->assertOk()->assertJsonStructure(['accessToken', 'deviceToken']);

    $plain = $response->json('deviceToken');
    expect($plain)->toBeString()->toHaveLength(64);

    $device = TrustedDevice::query()->where('user_id', $user->id)->firstOrFail();
    expect($device->token_hash)->toBe(TrustedDevice::hashToken($plain))
        ->and($device->user_agent)->toBe('PestBrowser/1.0')
        ->and($device->expires_at->isAfter(now()->addDays(29)))->toBeTrue();
    expect(TrustedDevice::query()->where('token_hash', $plain)->exists())->toBeFalse();
});

test('without remember_device no device token is issued', function (): void {
    $challenge = TwoFactorChallenge::factory()->create();

    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => TwoFactorChallengeFactory::CODE,
    ])->assertOk()->assertJsonMissingPath('deviceToken');

    expect(TrustedDevice::query()->count())->toBe(0);
});

test('spaces inside the typed code are ignored', function (): void {
    $challenge = TwoFactorChallenge::factory()->create();

    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => '123 456',
    ])->assertOk();
});

test('a consumed challenge cannot be replayed', function (): void {
    $challenge = TwoFactorChallenge::factory()->consumed()->create();

    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => TwoFactorChallengeFactory::CODE,
    ])->assertGone()
        ->assertJsonStructure(['message']);
});

test('an expired challenge is gone even with the right code', function (): void {
    $challenge = TwoFactorChallenge::factory()->expired()->create();

    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => TwoFactorChallengeFactory::CODE,
    ])->assertGone();
});

test('an unknown challenge is gone, not a validation error', function (): void {
    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => '00000000-0000-4000-8000-000000000000',
        'code' => TwoFactorChallengeFactory::CODE,
    ])->assertGone();
});

test('a wrong code is 401, counts an attempt and says how many are left', function (): void {
    config()->set('auth.two_factor.max_attempts', 5);
    $challenge = TwoFactorChallenge::factory()->create();

    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => '000000',
    ])->assertUnauthorized()
        ->assertJsonPath('attemptsLeft', 4)
        ->assertJsonMissingPath('accessToken');

    expect($challenge->fresh()?->attempts)->toBe(1)
        ->and($challenge->fresh()?->consumed_at)->toBeNull();
});

test('the last wrong attempt burns the challenge and the right code no longer works', function (): void {
    config()->set('auth.two_factor.max_attempts', 2);
    $challenge = TwoFactorChallenge::factory()->create();

    $this->postJson(route('auth.login.verify'), ['challenge_id' => $challenge->id, 'code' => '000000'])
        ->assertUnauthorized()
        ->assertJsonPath('attemptsLeft', 1);

    $this->postJson(route('auth.login.verify'), ['challenge_id' => $challenge->id, 'code' => '000000'])
        ->assertGone();

    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => TwoFactorChallengeFactory::CODE,
    ])->assertGone();
});

test('the form is validated before the challenge is looked up', function (): void {
    $this->postJson(route('auth.login.verify'), ['challenge_id' => 'nope', 'code' => '12'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge_id', 'code']);
});

test('verification requests are throttled per challenge and ip', function (): void {
    config()->set('auth.two_factor.verify_per_minute', 2);
    $challenge = TwoFactorChallenge::factory()->create();

    foreach (range(1, 2) as $attempt) {
        $this->postJson(route('auth.login.verify'), ['challenge_id' => $challenge->id, 'code' => '000000'])
            ->assertUnauthorized();
    }

    $this->postJson(route('auth.login.verify'), ['challenge_id' => $challenge->id, 'code' => '000000'])
        ->assertTooManyRequests();
});
