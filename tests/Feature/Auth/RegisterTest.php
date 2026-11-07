<?php

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Database\Factories\TwoFactorChallengeFactory;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    createPersonalAccessClient();
});

test('a visitor registers and gets the emailed code before any session', function (): void {
    Event::fake([Registered::class]);
    Notification::fake();

    $response = $this->postJson(route('auth.register'), [
        'name' => 'Ada Lovelace',
        'email' => ' Ada@Example.com ',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('method', 'email')
        ->assertJsonPath('destination', 'a***@example.com')
        ->assertJsonMissingPath('accessToken');

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();
    Event::assertDispatched(Registered::class);
    Notification::assertSentTo($user, TwoFactorCodeNotification::class);

    $challenge = TwoFactorChallenge::query()->findOrFail($response->json('challengeId'));
    expect($challenge->user_id)->toBe($user->id);
});

test('confirming the registration code opens the session and verifies the email', function (): void {
    $user = User::factory()->unverified()->create();
    $challenge = TwoFactorChallenge::factory()->for($user)->create();

    $this->postJson(route('auth.login.verify'), [
        'challenge_id' => $challenge->id,
        'code' => TwoFactorChallengeFactory::CODE,
    ])->assertOk()
        ->assertJsonPath('user.id', $user->id);

    expect($user->fresh()?->email_verified_at)->not->toBeNull();
});

test('with two-factor disabled a visitor registers and receives a session', function (): void {
    config()->set('auth.two_factor.enabled', false);
    Event::fake([Registered::class]);

    $response = $this->postJson(route('auth.register'), [
        'name' => 'Ada Lovelace',
        'email' => ' Ada@Example.com ',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['accessToken', 'tokenType', 'expiresAt', 'user' => ['id', 'email', 'name']])
        ->assertJsonPath('tokenType', 'Bearer')
        ->assertJsonPath('user.email', 'ada@example.com')
        ->assertJsonPath('user.name', 'Ada Lovelace');

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    expect(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
    Event::assertDispatched(Registered::class);

    $this->withToken($response->json('accessToken'))
        ->getJson(route('auth.me'))
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('registration validates the form', function (): void {
    $this->postJson(route('auth.register'), [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('an email can only register once', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson(route('auth.register'), [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});
