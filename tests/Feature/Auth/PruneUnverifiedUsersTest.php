<?php

use App\Models\TrustedDevice;
use App\Models\TwoFactorChallenge;
use App\Models\User;

beforeEach(function (): void {
    createPersonalAccessClient();
    config()->set('auth.two_factor.enabled', true);
    config()->set('auth.two_factor.unverified_user_days', 7);
});

function pruneUsers(): void
{
    test()->artisan('model:prune', ['--model' => User::class])->assertSuccessful();
}

test('a sign-up abandoned before its first code is dropped after the retention days', function (): void {
    $abandoned = User::factory()->unverified()->create(['created_at' => now()->subDays(8)]);
    TwoFactorChallenge::factory()->for($abandoned)->expired()->create();

    pruneUsers();

    expect(User::query()->find($abandoned->id))->toBeNull()
        ->and(TwoFactorChallenge::query()->where('user_id', $abandoned->id)->exists())->toBeFalse();
});

test('verified, recent and signed-in accounts are kept', function (): void {
    $verified = User::factory()->create(['created_at' => now()->subDays(30)]);
    $recent = User::factory()->unverified()->create(['created_at' => now()->subDays(6)]);
    $signedIn = User::factory()->unverified()->create(['created_at' => now()->subDays(30)]);
    $signedIn->createToken('deployer-client');
    TrustedDevice::factory()->for($signedIn)->create();

    pruneUsers();

    expect(User::query()->whereKey([$verified->id, $recent->id, $signedIn->id])->count())->toBe(3);
});

test('nothing is pruned while the second factor is off', function (): void {
    config()->set('auth.two_factor.enabled', false);
    $user = User::factory()->unverified()->create(['created_at' => now()->subDays(30)]);

    pruneUsers();

    expect(User::query()->find($user->id))->not->toBeNull();
});

test('zero retention days disables the rule', function (): void {
    config()->set('auth.two_factor.unverified_user_days', 0);
    $user = User::factory()->unverified()->create(['created_at' => now()->subDays(30)]);

    pruneUsers();

    expect(User::query()->find($user->id))->not->toBeNull();
});
