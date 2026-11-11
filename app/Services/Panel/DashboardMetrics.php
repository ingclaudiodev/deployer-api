<?php

namespace App\Services\Panel;

use App\Models\Role;
use App\Models\TrustedDevice;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Foundation\Application;
use Laravel\Passport\Passport;

/**
 * Numbers of the panel overview that the database answers on its own.
 */
class DashboardMetrics
{
    /**
     * @return array{users: int, newUsersThisWeek: int, unverifiedUsers: int, activeTokens: int, pendingChallenges: int, trustedDevices: int}
     */
    public function overview(): array
    {
        return [
            'users' => User::query()->count(),
            'newUsersThisWeek' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'unverifiedUsers' => User::query()->whereNull('email_verified_at')->count(),
            'activeTokens' => Passport::token()->newQuery()
                ->where('revoked', false)
                ->where('expires_at', '>', now())
                ->count(),
            'pendingChallenges' => TwoFactorChallenge::query()->pending()->count(),
            'trustedDevices' => TrustedDevice::query()->active()->count(),
        ];
    }

    /**
     * @return list<array{slug: string, name: string, users: int}>
     */
    public function usersPerRole(): array
    {
        return array_values(Role::query()
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role): array => [
                'slug' => $role->slug,
                'name' => $role->name,
                'users' => (int) $role->users_count,
            ])
            ->all());
    }

    /**
     * Sign-ups of each of the last `$days` days, oldest first, days without any included.
     *
     * @return list<array{date: string, total: int}>
     */
    public function signupsPerDay(int $days): array
    {
        $firstDay = now()->subDays($days - 1)->startOfDay();

        /** @var array<string, int> $totals */
        $totals = User::query()
            ->toBase()
            ->where('created_at', '>=', $firstDay)
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupByRaw('date(created_at)')
            ->pluck('total', 'day')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $firstDay->addDays($offset)->toDateString();
            $series[] = ['date' => $date, 'total' => $totals[$date] ?? 0];
        }

        return $series;
    }

    /**
     * @return list<array{id: int, name: string, email: string, role: string, verified: bool, createdAt: string|null}>
     */
    public function recentUsers(int $limit): array
    {
        return array_values(User::query()
            ->with('role')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->name,
                'verified' => $user->hasVerifiedEmail(),
                'createdAt' => $user->created_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * How this instance is configured. Names only: never a secret nor a connection string.
     *
     * @return array{environment: string, debug: bool, php: string, laravel: string, octane: string|null, apiUrl: string, frontendUrl: string, twoFactor: bool, queue: string, cache: string, session: string, mailer: string, aiModel: string}
     */
    public function system(): array
    {
        $octane = config('octane.server');

        return [
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'php' => PHP_VERSION,
            'laravel' => Application::VERSION,
            'octane' => is_string($octane) ? $octane : null,
            'apiUrl' => (string) config('app.url'),
            'frontendUrl' => (string) config('app.frontend_url'),
            'twoFactor' => (bool) config('auth.two_factor.enabled'),
            'queue' => (string) config('queue.default'),
            'cache' => (string) config('cache.default'),
            'session' => (string) config('session.driver'),
            'mailer' => (string) config('mail.default'),
            'aiModel' => (string) config('services.vertex_ai.model'),
        ];
    }
}
