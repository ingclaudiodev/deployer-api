<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    createPersonalAccessClient();
});

test('a visitor can register and receives a session', function (): void {
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
