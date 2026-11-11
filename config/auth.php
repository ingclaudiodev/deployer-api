<?php

use App\Auth\Adapters\PasswordAdapter;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'passport',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Adapters
    |--------------------------------------------------------------------------
    |
    | Each adapter is one way a user can prove their identity to the API
    | (see App\Auth\Contracts\AuthenticationAdapter). All of them end in the
    | same Passport token. Add OAuth providers here as they are implemented.
    |
    */

    'adapters' => [
        'password' => PasswordAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | API Tokens
    |--------------------------------------------------------------------------
    |
    | Name given to the Passport personal access tokens issued to clients and
    | how long they stay valid before the user must sign in again.
    |
    */

    'tokens' => [
        'name' => env('AUTH_TOKEN_NAME', 'deployer-client'),
        'expire_days' => (int) env('AUTH_TOKEN_EXPIRE_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttling
    |--------------------------------------------------------------------------
    |
    | Attempts per minute allowed on the public auth endpoints (login, register,
    | password reset), counted per email + IP. See the "auth" rate limiter.
    |
    */

    'throttle' => [
        'per_minute' => (int) env('AUTH_THROTTLE_PER_MINUTE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication (email one-time code)
    |--------------------------------------------------------------------------
    |
    | After the password is accepted, a numeric code is emailed and the session
    | only opens once it is confirmed. "expire_minutes" is how long a code lives,
    | "max_attempts" how many wrong codes burn the challenge, "resend_seconds"
    | the minimum wait before another email, "verify_per_minute" the rate limit
    | on confirm/resend requests per challenge and ip, and "trust_days" how long
    | a device remembered after a confirmed code skips the code.
    |
    */

    'two_factor' => [
        'enabled' => (bool) env('AUTH_TWO_FACTOR_ENABLED', true),
        'code_length' => (int) env('AUTH_TWO_FACTOR_CODE_LENGTH', 6),
        'expire_minutes' => (int) env('AUTH_TWO_FACTOR_EXPIRE_MINUTES', 10),
        'max_attempts' => (int) env('AUTH_TWO_FACTOR_MAX_ATTEMPTS', 5),
        'resend_seconds' => (int) env('AUTH_TWO_FACTOR_RESEND_SECONDS', 60),
        'verify_per_minute' => (int) env('AUTH_TWO_FACTOR_VERIFY_PER_MINUTE', 10),
        'trust_days' => (int) env('AUTH_TWO_FACTOR_TRUST_DAYS', 30),
        // Accounts that never confirmed their first code are dropped after this many days (0 keeps them).
        'unverified_user_days' => (int) env('AUTH_TWO_FACTOR_UNVERIFIED_USER_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Role
    |--------------------------------------------------------------------------
    |
    | Slug of the role every newly created account receives when none is set
    | explicitly. It must match a row of the `roles` table (super_admin, admin,
    | usuario, soporte). For now every account is an administrator.
    |
    */

    'default_role' => env('AUTH_DEFAULT_ROLE', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Welcome Broadcast
    |--------------------------------------------------------------------------
    |
    | After every sign-in a welcome card is pushed to the user's private channel
    | over Reverb. The browser only connects once it holds the new token, so the
    | push waits "delay_seconds" on the queue; otherwise it would leave before
    | anyone is subscribed and the toast would never show.
    |
    */

    'welcome' => [
        'delay_seconds' => (int) env('AUTH_WELCOME_DELAY_SECONDS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
            'frontend_path' => env('AUTH_RESET_PASSWORD_PATH', '/restablecer'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
