<?php

namespace App\Providers;

use App\Auth\Adapters\PasswordAdapter;
use App\Auth\AuthenticationManager;
use App\Auth\Contracts\AuthenticationAdapter;
use App\Auth\TokenIssuer;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

/**
 * Everything the API authentication system needs at boot: Passport token policy,
 * the adapter registry, the password-reset link and the login rate limiter.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthenticationManager::class, function ($app): AuthenticationManager {
            /** @var array<string, class-string<AuthenticationAdapter>> $drivers */
            $drivers = config('auth.adapters', [PasswordAdapter::DRIVER => PasswordAdapter::class]);

            return new AuthenticationManager($app, $drivers);
        });

        $this->app->singleton(TokenIssuer::class, function (): TokenIssuer {
            /** @var string $name */
            $name = config('auth.tokens.name');

            return new TokenIssuer($name);
        });
    }

    public function boot(): void
    {
        $this->configurePassport();
        $this->configurePasswordReset();
        $this->configureRateLimiting();
    }

    private function configurePassport(): void
    {
        /** @var int $days */
        $days = config('auth.tokens.expire_days');

        Passport::personalAccessTokensExpireIn(CarbonInterval::days($days));
    }

    /**
     * The reset link must open the SPA, not a Laravel route this API does not have.
     */
    private function configurePasswordReset(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            /** @var string $frontend */
            $frontend = config('app.frontend_url');
            /** @var string $path */
            $path = config('auth.passwords.users.frontend_path');

            return rtrim($frontend, '/').$path.'?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);
        });
    }

    /**
     * Brute-force protection keyed on the email being tried plus the caller's IP.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request): Limit {
            /** @var int $perMinute */
            $perMinute = config('auth.throttle.per_minute');
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinute($perMinute)->by($email.'|'.$request->ip());
        });
    }
}
