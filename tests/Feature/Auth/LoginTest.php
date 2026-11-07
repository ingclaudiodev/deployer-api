<?php

use App\Models\TrustedDevice;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Database\Factories\TrustedDeviceFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    createPersonalAccessClient();
});

test('valid credentials open a two-factor challenge and email the code', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'dev@acme.com']);

    $response = $this->postJson(route('auth.login'), [
        'email' => 'Dev@Acme.com',
        'password' => 'password',
    ]);

    $response->assertAccepted()
        ->assertJsonStructure(['challengeId', 'method', 'destination', 'codeLength', 'expiresAt', 'resendAvailableAt'])
        ->assertJsonPath('method', 'email')
        ->assertJsonPath('destination', 'd***@acme.com')
        ->assertJsonPath('codeLength', 6)
        ->assertJsonMissingPath('accessToken');

    $challenge = TwoFactorChallenge::query()->findOrFail($response->json('challengeId'));
    expect($challenge->user_id)->toBe($user->id)
        ->and($challenge->consumed_at)->toBeNull()
        ->and($challenge->expires_at->isFuture())->toBeTrue();

    Notification::assertSentTo($user, TwoFactorCodeNotification::class, function (TwoFactorCodeNotification $notification) use ($challenge): bool {
        return strlen($notification->code) === 6
            && ctype_digit($notification->code)
            && Hash::check($notification->code, $challenge->code_hash);
    });
});

test('a remembered device skips the code and gets a session straight away', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'dev@acme.com']);
    $device = TrustedDevice::factory()->for($user)->create();

    $response = $this->postJson(route('auth.login'), [
        'email' => 'dev@acme.com',
        'password' => 'password',
        'device_token' => TrustedDeviceFactory::TOKEN,
    ]);

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonMissingPath('challengeId');

    expect($response->json('accessToken'))->toBeString()->not->toBeEmpty()
        ->and($device->fresh()?->last_used_at?->isAfter(now()->subMinute()))->toBeTrue()
        ->and(TwoFactorChallenge::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('an expired, foreign or unknown device token still asks for the code', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'dev@acme.com']);
    TrustedDevice::factory()->for($user)->expired()->create();
    TrustedDevice::factory()->create(['token_hash' => TrustedDevice::hashToken('someone-elses')]);

    foreach ([TrustedDeviceFactory::TOKEN, 'someone-elses', 'made-up'] as $token) {
        $this->postJson(route('auth.login'), [
            'email' => 'dev@acme.com',
            'password' => 'password',
            'device_token' => $token,
        ])->assertAccepted()->assertJsonMissingPath('accessToken');
    }
});

test('a device token never replaces the password', function (): void {
    $user = User::factory()->create(['email' => 'dev@acme.com']);
    TrustedDevice::factory()->for($user)->create();

    $this->postJson(route('auth.login'), [
        'email' => 'dev@acme.com',
        'password' => 'nope',
        'device_token' => TrustedDeviceFactory::TOKEN,
    ])->assertUnauthorized();
});

test('with two-factor disabled valid credentials return a bearer token and the user', function (): void {
    config()->set('auth.two_factor.enabled', false);
    $user = User::factory()->create(['email' => 'dev@acme.com']);

    $response = $this->postJson(route('auth.login'), [
        'email' => 'Dev@Acme.com',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('tokenType', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', 'dev@acme.com');

    expect($response->json('accessToken'))->toBeString()->not->toBeEmpty();
    expect($response->json('expiresAt'))->toBeString();

    $this->withToken($response->json('accessToken'))
        ->getJson(route('auth.me'))
        ->assertOk()
        ->assertJsonPath('email', 'dev@acme.com');
});

test('a wrong password opens no challenge and sends no email', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'dev@acme.com']);

    $this->postJson(route('auth.login'), [
        'email' => 'dev@acme.com',
        'password' => 'nope',
    ])->assertUnauthorized();

    expect(TwoFactorChallenge::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a wrong password is rejected with 401, not 422', function (): void {
    User::factory()->create(['email' => 'dev@acme.com']);

    $this->postJson(route('auth.login'), [
        'email' => 'dev@acme.com',
        'password' => 'nope',
    ])->assertUnauthorized()
        ->assertJsonStructure(['message']);
});

test('an unknown email is rejected the same way as a wrong password', function (): void {
    $this->postJson(route('auth.login'), [
        'email' => 'ghost@acme.com',
        'password' => 'password',
    ])->assertUnauthorized();
});

test('the form is validated before any credential check', function (): void {
    $this->postJson(route('auth.login'), ['email' => 'nope', 'password' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

test('repeated attempts are throttled per email and ip', function (): void {
    RateLimiter::clear('auth');
    config()->set('auth.throttle.per_minute', 2);
    User::factory()->create(['email' => 'dev@acme.com']);

    foreach (range(1, 2) as $attempt) {
        $this->postJson(route('auth.login'), ['email' => 'dev@acme.com', 'password' => 'nope'])
            ->assertUnauthorized();
    }

    $this->postJson(route('auth.login'), ['email' => 'dev@acme.com', 'password' => 'nope'])
        ->assertTooManyRequests();
});
