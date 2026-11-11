<?php

use App\Models\User;

test('signing out closes the session and returns to the home page', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->post(route('panel.logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));
});

test('guests cannot call the sign-out endpoint', function (): void {
    $this->post(route('panel.logout'))->assertRedirect(route('panel.login'));
});
