<?php

use App\Models\User;
use Laravel\Passport\Passport;

test('me returns the public shape of the authenticated user', function (): void {
    $user = User::factory()->create(['name' => 'Grace', 'email' => 'grace@acme.com']);
    Passport::actingAs($user);

    $this->getJson(route('auth.me'))
        ->assertOk()
        ->assertExactJson(['id' => $user->id, 'email' => 'grace@acme.com', 'name' => 'Grace']);
});

test('me rejects anonymous requests with json', function (): void {
    $this->getJson(route('auth.me'))
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});
