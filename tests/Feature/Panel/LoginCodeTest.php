<?php

use App\Auth\Panel\PanelSession;
use App\Auth\TwoFactor\ChallengeService;
use App\Models\Role;
use App\Models\TrustedDevice;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\Auth\TwoFactorCodeNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/**
 * Passes the password step and returns the code that was emailed.
 */
function openPanelChallenge(User $user): string
{
    Notification::fake();

    test()->post(route('panel.login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('panel.login.code'));

    $code = null;
    Notification::assertSentTo($user, TwoFactorCodeNotification::class, function (TwoFactorCodeNotification $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    return (string) $code;
}

function wrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

beforeEach(function (): void {
    $this->operator = User::factory()->superAdmin()->create(['email' => 'claudio@deployer.cl']);
});

test('the code page shows a masked inbox and never the challenge id', function (): void {
    openPanelChallenge($this->operator);
    $challenge = TwoFactorChallenge::query()->sole();

    $this->get(route('panel.login.code'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/LoginCode')
            ->where('destination', 'c***@deployer.cl')
            ->where('codeLength', 6)
            ->where('trustDays', 30)
            ->has('expiresAt')
            ->has('resendAvailableAt')
            ->missing('challengeId'))
        ->assertDontSee($challenge->id);
});

test('the code page sends back to the password step when no challenge is pending', function (): void {
    $this->get(route('panel.login.code'))->assertRedirect(route('panel.login'));
});

test('the emailed code opens the session and consumes the challenge', function (): void {
    $code = openPanelChallenge($this->operator);

    $this->post(route('panel.login.code.verify'), ['code' => $code])
        ->assertRedirect(route('panel.dashboard'))
        ->assertSessionMissing(PanelSession::PENDING_CHALLENGE)
        ->assertCookieMissing(PanelSession::DEVICE_COOKIE);

    $this->assertAuthenticatedAs($this->operator);
    expect(TwoFactorChallenge::query()->sole()->consumed_at)->not->toBeNull()
        ->and(TrustedDevice::query()->count())->toBe(0);
});

test('a code typed with spaces is accepted', function (): void {
    $code = openPanelChallenge($this->operator);

    $this->post(route('panel.login.code.verify'), ['code' => substr($code, 0, 3).' '.substr($code, 3)])
        ->assertRedirect(route('panel.dashboard'));

    $this->assertAuthenticatedAs($this->operator);
});

test('remembering the browser stores a device and sets its cookie', function (): void {
    $code = openPanelChallenge($this->operator);

    $response = $this->post(route('panel.login.code.verify'), ['code' => $code, 'remember_device' => '1']);

    $response->assertRedirect(route('panel.dashboard'))->assertCookie(PanelSession::DEVICE_COOKIE);

    $device = TrustedDevice::query()->sole();
    $cookie = $response->getCookie(PanelSession::DEVICE_COOKIE);

    expect($device->user_id)->toBe($this->operator->id)
        ->and($device->token_hash)->toBe(TrustedDevice::hashToken((string) $cookie?->getValue()))
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getPath())->toBe('/panel');
});

test('a wrong code is refused, counted and leaves the challenge open', function (): void {
    $code = openPanelChallenge($this->operator);

    $this->from(route('panel.login.code'))
        ->post(route('panel.login.code.verify'), ['code' => wrongCode($code)])
        ->assertRedirect(route('panel.login.code'))
        ->assertSessionHasErrors(['code' => __('The code is not valid.')])
        ->assertSessionHas(PanelSession::PENDING_CHALLENGE);

    $this->assertGuest();
    expect(TwoFactorChallenge::query()->sole()->attempts)->toBe(1);
});

test('too many wrong codes burn the challenge and send back to the password step', function (): void {
    config(['auth.two_factor.max_attempts' => 1]);
    $this->app->forgetInstance(ChallengeService::class);
    $code = openPanelChallenge($this->operator);

    $this->post(route('panel.login.code.verify'), ['code' => wrongCode($code)])
        ->assertRedirect(route('panel.login'))
        ->assertSessionHasErrors(['email' => __('Too many wrong codes. Please sign in again.')])
        ->assertSessionMissing(PanelSession::PENDING_CHALLENGE);

    $this->assertGuest();
});

test('an expired challenge sends back to the password step', function (): void {
    $code = openPanelChallenge($this->operator);

    $this->travel(11)->minutes();

    $this->post(route('panel.login.code.verify'), ['code' => $code])
        ->assertRedirect(route('panel.login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a code without a pending challenge in the session opens nothing', function (): void {
    $code = openPanelChallenge($this->operator);
    $this->flushSession();

    $this->post(route('panel.login.code.verify'), ['code' => $code])
        ->assertRedirect(route('panel.login'));

    $this->assertGuest();
    expect(TwoFactorChallenge::query()->sole()->consumed_at)->toBeNull();
});

test('the code must have the configured number of digits', function (string $code): void {
    openPanelChallenge($this->operator);

    $this->post(route('panel.login.code.verify'), ['code' => $code])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
})->with(['', '12345', '1234567', 'abcdef']);

test('an operator demoted between the two steps does not get in', function (): void {
    $code = openPanelChallenge($this->operator);
    $this->operator->forceFill(['role_id' => Role::findBySlug(Role::ADMIN)->id])->save();

    $this->post(route('panel.login.code.verify'), ['code' => $code])
        ->assertRedirect(route('panel.login'));

    $this->assertGuest();
});

test('a new code can be requested once the wait is over', function (): void {
    openPanelChallenge($this->operator);

    $this->travel(61)->seconds();

    $this->post(route('panel.login.code.resend'))->assertRedirect(route('panel.login.code'));

    Notification::assertSentToTimes($this->operator, TwoFactorCodeNotification::class, 2);
});

test('asking for a new code too soon is refused and sends nothing', function (): void {
    openPanelChallenge($this->operator);

    $this->from(route('panel.login.code'))
        ->post(route('panel.login.code.resend'))
        ->assertRedirect(route('panel.login.code'))
        ->assertSessionHasErrors(['code' => __('A code was sent recently. Please wait before asking for another.')]);

    Notification::assertSentToTimes($this->operator, TwoFactorCodeNotification::class, 1);
});
