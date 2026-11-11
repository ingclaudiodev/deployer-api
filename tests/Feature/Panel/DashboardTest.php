<?php

use App\Models\TrustedDevice;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Panel\SystemHealth;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;

test('guests are sent to the sign-in page', function (): void {
    $this->get(route('panel.dashboard'))->assertRedirect(route('panel.login'));
});

test('a signed-in account that is not a super administrator gets 403', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('panel.dashboard'))
        ->assertForbidden();
});

test('the overview counts accounts, sessions and pending sign-ins', function (): void {
    $this->freezeSecond();
    createPersonalAccessClient();
    $operator = User::factory()->superAdmin()->create(['name' => 'Claudio', 'email' => 'claudio@deployer.cl']);
    $admin = User::factory()->admin()->create(['created_at' => now()->subDays(30)]);
    User::factory()->usuario()->unverified()->create(['created_at' => now()->subDays(2)]);

    $admin->createToken('live');
    $admin->createToken('revoked')->token->revoke();
    TwoFactorChallenge::factory()->for($admin)->create();
    TwoFactorChallenge::factory()->for($admin)->create(['expires_at' => now()->subMinute()]);
    TrustedDevice::factory()->for($admin)->create();
    TrustedDevice::factory()->for($admin)->expired()->create(['token_hash' => TrustedDevice::hashToken('old')]);

    $this->actingAs($operator)
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('panel/Dashboard')
            ->where('stats', [
                'users' => 3,
                'newUsersThisWeek' => 2,
                'unverifiedUsers' => 1,
                'activeTokens' => 1,
                'pendingChallenges' => 1,
                'trustedDevices' => 1,
            ])
            ->where('roles', [
                ['slug' => 'super_admin', 'name' => 'Super administrador', 'users' => 1],
                ['slug' => 'admin', 'name' => 'Administrador', 'users' => 1],
                ['slug' => 'usuario', 'name' => 'Usuario', 'users' => 1],
                ['slug' => 'soporte', 'name' => 'Soporte', 'users' => 0],
            ])
            ->has('signups', 14)
            ->where('signups.13', ['date' => now()->toDateString(), 'total' => 1])
            ->where('signups.11', ['date' => now()->subDays(2)->toDateString(), 'total' => 1])
            ->where('signups.0.total', 0)
            ->has('recentUsers', 3)
            ->where('recentUsers.0.verified', false)
            ->where('recentUsers.0.createdAt', now()->subDays(2)->toIso8601String())
            ->where('recentUsers.2.email', 'claudio@deployer.cl')
            ->where('recentUsers.2.role', 'Super administrador')
            ->where('horizonUrl', url('horizon'))
            ->where('system.environment', 'testing')
            ->where('auth.user', [
                'id' => $operator->id,
                'name' => 'Claudio',
                'email' => 'claudio@deployer.cl',
                'role' => 'Super administrador',
            ])
            ->missing('health'));
});

test('the page never ships password hashes nor secrets', function (): void {
    $operator = User::factory()->superAdmin()->create();

    $content = $this->actingAs($operator)->get(route('panel.dashboard'))->getContent();

    expect($content)->not->toContain($operator->password)
        ->not->toContain('remember_token')
        ->not->toContain((string) config('app.key'));
});

test('the live state of the services is loaded after the page', function (): void {
    $snapshot = [
        'database' => ['ok' => true, 'latencyMs' => 1.2],
        'redis' => ['ok' => false, 'latencyMs' => null],
        'horizon' => ['status' => 'inactive', 'processes' => 0, 'jobsPerMinute' => 0, 'recentJobs' => 0, 'failedJobs' => 3, 'maxWaitSeconds' => 0],
    ];
    $this->mock(SystemHealth::class, fn (MockInterface $mock) => $mock->shouldReceive('snapshot')->once()->andReturn($snapshot));

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('panel.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('health')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('health', $snapshot)));
});

test('only super administrators may open Horizon', function (string $role, bool $allowed): void {
    $user = User::factory()->role($role)->create();

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBe($allowed);
})->with([
    ['super_admin', true],
    ['admin', false],
    ['usuario', false],
    ['soporte', false],
]);

test('Horizon answers 403 to guests and to accounts that are not super administrators', function (): void {
    $this->get('/horizon')->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())->get('/horizon')->assertForbidden();
});

test('Horizon opens for a super administrator with a panel session', function (): void {
    $this->actingAs(User::factory()->superAdmin()->create())->get('/horizon')->assertOk();
});
