<?php

use App\Models\Role;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a verified super administrator with the password that was typed', function (): void {
    $this->artisan('panel:super-admin', ['email' => 'Claudio@Deployer.cl'])
        ->expectsQuestion('Contraseña', 'a-long-passphrase')
        ->expectsOutputToContain('Super administrador claudio@deployer.cl creado.')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->email)->toBe('claudio@deployer.cl')
        ->and($user->name)->toBe('claudio')
        ->and($user->isSuperAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('a-long-passphrase', $user->password))->toBeTrue();
});

test('running it again updates the same account instead of adding another', function (): void {
    $existing = User::factory()->usuario()->unverified()->create(['name' => 'Claudio V.', 'email' => 'claudio@deployer.cl']);
    TrustedDevice::factory()->for($existing)->create();

    $this->artisan('panel:super-admin', ['email' => 'claudio@deployer.cl', '--password' => 'another-passphrase'])
        ->expectsOutputToContain('actualizada')
        ->assertSuccessful();

    $user = User::query()->sole();

    expect($user->id)->toBe($existing->id)
        ->and($user->name)->toBe('Claudio V.')
        ->and($user->role->slug)->toBe(Role::SUPER_ADMIN)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('another-passphrase', $user->password))->toBeTrue()
        ->and(TrustedDevice::query()->count())->toBe(0);
});

test('the name option sets the name shown in the panel', function (): void {
    $this->artisan('panel:super-admin', ['email' => 'ops@deployer.cl', '--name' => 'Operaciones', '--password' => 'a-long-passphrase'])
        ->assertSuccessful();

    expect(User::query()->sole()->name)->toBe('Operaciones');
});

test('it refuses an invalid email or a short password and writes nothing', function (string $email, string $password): void {
    $this->artisan('panel:super-admin', ['email' => $email, '--password' => $password])
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'not an email' => ['claudio', 'a-long-passphrase'],
    'short password' => ['claudio@deployer.cl', 'short'],
]);
