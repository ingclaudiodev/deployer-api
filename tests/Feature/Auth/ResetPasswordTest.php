<?php

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    createPersonalAccessClient();
    // These tests open sessions through the login endpoint; the second factor is covered elsewhere.
    config()->set('auth.two_factor.enabled', false);
});

test('a valid token sets the new password and closes open sessions', function (): void {
    $user = User::factory()->create(['email' => 'dev@acme.com']);
    TrustedDevice::factory()->for($user)->create();
    $oldSession = $this->postJson(route('auth.login'), ['email' => 'dev@acme.com', 'password' => 'password'])->json('accessToken');
    $token = Password::broker()->createToken($user);

    $this->postJson(route('auth.reset-password'), [
        'token' => $token,
        'email' => 'dev@acme.com',
        'password' => 'new-correct-horse',
        'password_confirmation' => 'new-correct-horse',
    ])->assertOk()->assertJsonStructure(['message']);

    expect(Hash::check('new-correct-horse', $user->fresh()->password))->toBeTrue()
        ->and(TrustedDevice::query()->where('user_id', $user->id)->count())->toBe(0);
    $this->withToken($oldSession)->getJson(route('auth.me'))->assertUnauthorized();
    $this->postJson(route('auth.login'), ['email' => 'dev@acme.com', 'password' => 'new-correct-horse'])->assertOk();
});

test('an invalid token is a validation error on email', function (): void {
    User::factory()->create(['email' => 'dev@acme.com']);

    $this->postJson(route('auth.reset-password'), [
        'token' => 'bogus',
        'email' => 'dev@acme.com',
        'password' => 'new-correct-horse',
        'password_confirmation' => 'new-correct-horse',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('the new password must be confirmed and strong enough', function (): void {
    $this->postJson(route('auth.reset-password'), [
        'token' => 'x',
        'email' => 'dev@acme.com',
        'password' => 'short',
        'password_confirmation' => 'other',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});
