<?php

namespace App\Console\Commands;

use App\Auth\TwoFactor\TrustedDeviceService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates the account that operates the panel, or promotes an existing one. It
 * writes exactly one row of `users` (found by email, so running it again only
 * updates that row) and is the only place that hands out the super_admin role.
 *
 * The password is asked for, not stored anywhere in the repository: an operator
 * account must not ship with the code. --password exists for non-interactive
 * runs (a Cloud Run job reading a secret), where nobody can answer a prompt.
 */
#[Signature('panel:super-admin
    {email : Email of the account to create or promote}
    {--name= : Name shown in the panel; defaults to the part of the email before the @}
    {--password= : Password for non-interactive runs; asked for when omitted}')]
#[Description('Create or promote the super administrator that signs in to the panel')]
class CreateSuperAdminCommand extends Command
{
    private const int MIN_PASSWORD_LENGTH = 12;

    public function handle(TrustedDeviceService $devices): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = $this->option('password');
        $password = is_string($password) && $password !== ''
            ? $password
            : $this->secret('Contraseña');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $name = $this->option('name');

        // role_id is not mass-assignable on purpose: this command is code that decided the promotion.
        $user->forceFill([
            'name' => is_string($name) && $name !== '' ? $name : ($user->name ?? Str::before($email, '@')),
            'password' => $password,
            'role_id' => Role::findBySlug(Role::SUPER_ADMIN)->id,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        if ($existed) {
            // A new password must drop every shortcut around the second factor.
            $devices->revokeAll($user);
        }

        $this->components->info($existed
            ? "Cuenta {$email} actualizada: ahora es super administrador."
            : "Super administrador {$email} creado.");

        return self::SUCCESS;
    }
}
