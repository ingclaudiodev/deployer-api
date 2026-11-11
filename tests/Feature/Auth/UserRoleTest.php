<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Passport\Passport;

beforeEach(function (): void {
    createPersonalAccessClient();
});

test('the migration ships the four fixed roles', function (): void {
    expect(Role::query()->pluck('slug')->sort()->values()->all())
        ->toBe(['admin', 'soporte', 'super_admin', 'usuario']);
});

test('a visitor who registers becomes an administrator', function (): void {
    config()->set('auth.two_factor.enabled', false);

    $this->postJson(route('auth.register'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertCreated()
        ->assertJsonPath('user.role', 'admin');

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
    expect($user->isAdmin())->toBeTrue()
        ->and($user->isSuperAdmin())->toBeFalse();
});

test('the default role follows the configuration', function (): void {
    config()->set('auth.default_role', Role::USUARIO);

    $user = User::factory()->create();

    expect($user->role->slug)->toBe('usuario');
});

test('a factory state assigns another role and me exposes it', function (): void {
    $user = User::factory()->soporte()->create();
    Passport::actingAs($user);

    $this->getJson(route('auth.me'))
        ->assertOk()
        ->assertJsonPath('role', 'soporte');

    expect($user->hasRole(Role::SOPORTE, Role::ADMIN))->toBeTrue()
        ->and($user->hasRole(Role::ADMIN))->toBeFalse();
});

test('a super admin also counts as admin, but not the other way round', function (): void {
    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();

    expect($superAdmin->isAdmin())->toBeTrue()
        ->and($superAdmin->hasRole(Role::SOPORTE))->toBeFalse()
        ->and($admin->isSuperAdmin())->toBeFalse()
        ->and($admin->isAdmin())->toBeTrue();
});

test('the role cannot be set through mass assignment', function (): void {
    $superAdminId = Role::findBySlug(Role::SUPER_ADMIN)->id;

    $user = User::query()->create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'correct-horse-battery',
        'role_id' => $superAdminId,
    ]);

    expect($user->role->slug)->toBe('admin');
});

test('a role still held by users cannot be deleted', function (): void {
    User::factory()->soporte()->create();

    Role::findBySlug(Role::SOPORTE)->delete();
})->throws(QueryException::class);
