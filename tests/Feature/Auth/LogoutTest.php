<?php

use App\Models\User;

beforeEach(function (): void {
    createPersonalAccessClient();
    // These tests open sessions through the login endpoint; the second factor is covered elsewhere.
    config()->set('auth.two_factor.enabled', false);
});

test('logout revokes only the token used on the request', function (): void {
    $user = User::factory()->create();
    $phone = $this->postJson(route('auth.login'), ['email' => $user->email, 'password' => 'password'])->json('accessToken');
    $laptop = $this->postJson(route('auth.login'), ['email' => $user->email, 'password' => 'password'])->json('accessToken');

    $this->withToken($phone)->postJson(route('auth.logout'))->assertNoContent();

    // The api guard caches the resolved user for the life of the app instance; real
    // requests get a fresh instance each time, so drop it here to behave the same way.
    $this->app['auth']->forgetGuards();

    $this->withToken($phone)->getJson(route('auth.me'))->assertUnauthorized();
    $this->withToken($laptop)->getJson(route('auth.me'))->assertOk();
});

test('logout requires a token', function (): void {
    $this->postJson(route('auth.logout'))->assertUnauthorized();
});
