<?php

use App\Auth\Panel\PanelSession;
use App\Auth\TwoFactor\ChallengeService;
use App\Models\TrustedDevice;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Database\Factories\TrustedDeviceFactory;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

test('the sign-in page renders for guests', function (): void {
    $this->get(route('panel.login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('panel/Login'));
});

test('a signed-in operator is sent from the sign-in page to the panel', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('panel.login'))
        ->assertRedirect(route('panel.dashboard'));
});

test('the right password opens a challenge and emails the code instead of a session', function (): void {
    Notification::fake();
    $user = User::factory()->superAdmin()->create(['email' => 'ops@deployer.cl']);

    $response = $this->post(route('panel.login.store'), [
        'email' => ' Ops@Deployer.cl ',
        'password' => 'password',
    ]);

    $challenge = TwoFactorChallenge::query()->sole();

    $response->assertRedirect(route('panel.login.code'))
        ->assertSessionHas(PanelSession::PENDING_CHALLENGE, $challenge->id);
    expect($challenge->user_id)->toBe($user->id);
    $this->assertGuest();
    Notification::assertSentTo($user, TwoFactorCodeNotification::class);
});

test('a wrong password is refused with the same message as an unknown account', function (string $email): void {
    Notification::fake();
    User::factory()->superAdmin()->create(['email' => 'ops@deployer.cl']);

    $this->from(route('panel.login'))
        ->post(route('panel.login.store'), ['email' => $email, 'password' => 'not-the-password'])
        ->assertRedirect(route('panel.login'))
        ->assertSessionHasErrors(['email' => __('The email or password is incorrect.')]);

    $this->assertGuest();
    Notification::assertNothingSent();
})->with(['ops@deployer.cl', 'nobody@deployer.cl']);

test('an account that is not a super administrator is refused even with the right password', function (string $role): void {
    Notification::fake();
    User::factory()->role($role)->create(['email' => 'dev@acme.com']);

    $this->post(route('panel.login.store'), ['email' => 'dev@acme.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('The email or password is incorrect.')]);

    $this->assertGuest();
    expect(TwoFactorChallenge::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['admin', 'usuario', 'soporte']);

test('email and password are required', function (): void {
    $this->post(route('panel.login.store'), [])
        ->assertSessionHasErrors(['email', 'password']);
});

test('the password alone opens the session when the second factor is off', function (): void {
    config(['auth.two_factor.enabled' => false]);
    $this->app->forgetInstance(ChallengeService::class);
    $user = User::factory()->superAdmin()->create(['email' => 'ops@deployer.cl']);

    $this->post(route('panel.login.store'), ['email' => 'ops@deployer.cl', 'password' => 'password'])
        ->assertRedirect(route('panel.dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a remembered browser skips the code', function (): void {
    Notification::fake();
    $user = User::factory()->superAdmin()->create(['email' => 'ops@deployer.cl']);
    TrustedDevice::factory()->for($user)->create();

    $this->withCookie(PanelSession::DEVICE_COOKIE, TrustedDeviceFactory::TOKEN)
        ->post(route('panel.login.store'), ['email' => 'ops@deployer.cl', 'password' => 'password'])
        ->assertRedirect(route('panel.dashboard'));

    $this->assertAuthenticatedAs($user);
    Notification::assertNothingSent();
});

test('the device cookie of another account still asks for the code', function (): void {
    Notification::fake();
    User::factory()->superAdmin()->create(['email' => 'ops@deployer.cl']);
    TrustedDevice::factory()->create();

    $this->withCookie(PanelSession::DEVICE_COOKIE, TrustedDeviceFactory::TOKEN)
        ->post(route('panel.login.store'), ['email' => 'ops@deployer.cl', 'password' => 'password'])
        ->assertRedirect(route('panel.login.code'));

    $this->assertGuest();
});
