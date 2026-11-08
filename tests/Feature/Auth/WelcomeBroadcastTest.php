<?php

use App\Events\Auth\SessionStarted;
use App\Events\Auth\UserWelcomed;
use App\Listeners\Auth\BroadcastWelcome;
use App\Models\TrustedDevice;
use App\Models\User;
use Database\Factories\TrustedDeviceFactory;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    createPersonalAccessClient();
});

test('the welcome push waits on the queue for the configured seconds', function (): void {
    Queue::fake();
    config()->set('auth.welcome.delay_seconds', 7);
    $user = User::factory()->create();

    SessionStarted::dispatch($user);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === BroadcastWelcome::class);
    expect((new BroadcastWelcome)->withDelay(new SessionStarted($user)))->toBe(7);
});

test('the listener broadcasts the welcome card to the user who signed in', function (): void {
    Event::fake([UserWelcomed::class]);
    $user = User::factory()->create();

    (new BroadcastWelcome)->handle(new SessionStarted($user));

    Event::assertDispatched(UserWelcomed::class, fn (UserWelcomed $event): bool => $event->user->is($user));
});

test('the welcome card goes to the private channel of the user as auth.welcome with a title and a body', function (): void {
    config()->set('app.name', 'deployer.cl');
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@acme.com']);

    $event = new UserWelcomed($user);

    expect(array_map(fn ($channel): string => (string) $channel->name, $event->broadcastOn()))
        ->toBe(["private-App.Models.User.{$user->id}"])
        ->and($event->broadcastAs())->toBe('auth.welcome')
        ->and($event->broadcastWith())
        ->toHaveKeys(['title', 'body', 'user'])
        ->and($event->broadcastWith()['title'])->toBe('Bienvenido a deployer.cl, Ada')
        ->and($event->broadcastWith()['body'])->not->toBeEmpty()
        ->and($event->broadcastWith()['user'])->toBe(['id' => $user->id, 'name' => 'Ada']);
});

test('a user without a name is welcomed by email', function (): void {
    $user = User::factory()->create(['name' => '', 'email' => 'ada@acme.com']);

    expect((new UserWelcomed($user))->broadcastWith()['title'])->toContain('ada@acme.com');
});

test('a sign-in that opens a session raises SessionStarted; one that asks for the code does not', function (): void {
    Event::fake([SessionStarted::class]);
    $user = User::factory()->create(['email' => 'dev@acme.com']);

    $this->postJson(route('auth.login'), ['email' => 'dev@acme.com', 'password' => 'password'])
        ->assertAccepted();
    Event::assertNotDispatched(SessionStarted::class);

    TrustedDevice::factory()->for($user)->create();
    $this->postJson(route('auth.login'), [
        'email' => 'dev@acme.com',
        'password' => 'password',
        'device_token' => TrustedDeviceFactory::TOKEN,
    ])->assertOk();

    Event::assertDispatched(SessionStarted::class, fn (SessionStarted $event): bool => $event->user->is($user));
});

describe('channel authorization', function (): void {
    beforeEach(function (): void {
        // The null driver of the test suite never runs the channel callbacks; Reverb's
        // authorizer only signs the response with the app secret, no server involved.
        // Channels are registered per driver at boot, so they are loaded again on this one.
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        require base_path('routes/channels.php');
    });

    test('a bearer token authorizes its own private channel', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test')->accessToken;

        $this->withToken($token)->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-App.Models.User.{$user->id}",
        ])->assertOk()->assertJsonStructure(['auth']);
    });

    test('another user\'s channel is forbidden', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('test')->accessToken;

        $this->withToken($token)->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-App.Models.User.{$other->id}",
        ])->assertForbidden();
    });

    test('without a token the channel cannot be authorized', function (): void {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.1',
        ])->assertUnauthorized();
    });
});
