<?php

namespace App\Models;

use App\Auth\TwoFactor\ChallengeService;
use App\Notifications\Auth\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property int $id
 * @property int $role_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role $role
 * @property-read Collection<int, TwoFactorChallenge> $twoFactorChallenges
 * @property-read Collection<int, TrustedDevice> $trustedDevices
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, RepositoryConnection> $repositoryConnections
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Prunable;

    /**
     * Accounts created without an explicit role get the configured default
     * (`auth.default_role`), so sign-up and factories never have to know it.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->role_id ??= Role::findBySlug((string) config('auth.default_role'))->id;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The single role this user holds. Deliberately not mass-assignable: it only
     * changes through code that decided the user may be promoted.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Whether the user holds one of the given roles, directly or because their own
     * role implies it ({@see Role::slugsCovered()}): a super_admin passes `hasRole('admin')`.
     */
    public function hasRole(string ...$slugs): bool
    {
        return array_intersect($this->role->slugsCovered(), $slugs) !== [];
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    /**
     * Pending and past second-factor steps of this user's sign-ins.
     *
     * @return HasMany<TwoFactorChallenge, $this>
     */
    public function twoFactorChallenges(): HasMany
    {
        return $this->hasMany(TwoFactorChallenge::class);
    }

    /**
     * Devices remembered after a confirmed code; the password alone signs in from them.
     *
     * @return HasMany<TrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * Repositories this user deploys as services.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * GitHub and GitLab accounts this user connected.
     *
     * @return HasMany<RepositoryConnection, $this>
     */
    public function repositoryConnections(): HasMany
    {
        return $this->hasMany(RepositoryConnection::class);
    }

    /**
     * Sign-ups abandoned before their first confirmed code. They hold the email
     * hostage, so `model:prune` drops them after `two_factor.unverified_user_days`.
     *
     * Only meaningful while the second factor is on: with it off nobody is ever
     * marked verified, so the rule stays inert. Accounts that did open a session
     * are kept as well, in case the factor was switched on over an existing base.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = (int) config('auth.two_factor.unverified_user_days');

        if ($days <= 0 || ! config('auth.two_factor.enabled')) {
            return static::query()->whereKey([]);
        }

        return static::query()
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subDays($days))
            ->whereDoesntHave('tokens');
    }

    /**
     * Deployer never emails a verification link: confirming the sign-in code proves
     * the address ({@see ChallengeService::verify()}). The
     * framework's `Registered` listener calls this, so it must stay a no-op.
     */
    public function sendEmailVerificationNotification(): void
    {
        // Intentionally empty: the two-factor code is the verification.
    }

    /**
     * Send Deployer's own reset email instead of the framework default.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
