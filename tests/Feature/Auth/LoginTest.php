<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    createPersonalAccessClient();
});

test('valid credentials return a bearer token and the user', function (): void {
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
