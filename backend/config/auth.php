<?php

use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;

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
            'model' => env('AUTH_MODEL', UserModel::class),
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

    /*
    |--------------------------------------------------------------------------
    | Terms of service version
    |--------------------------------------------------------------------------
    */

    'terms' => [
        'current_version' => env('AUTH_TERMS_CURRENT_VERSION', '2026-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invite allowlist
    |--------------------------------------------------------------------------
    */

    'invite_allowlist' => [
        'path' => env('AUTH_INVITE_ALLOWLIST_PATH', base_path('config/invite-allowlist.testing.json')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'registration' => [
            'max_attempts' => 5,
            'decay_seconds' => 3600,
        ],
        'login' => [
            'email_ip' => [
                'max_attempts' => 5,
                'decay_seconds' => 60,
            ],
            'ip' => [
                'max_attempts' => 30,
                'decay_seconds' => 60,
            ],
        ],
        'email_verification_resend' => [
            'max_attempts' => 3,
            'decay_seconds' => 3600,
        ],
        'email_verification_verify' => [
            'max_attempts' => 5,
            'decay_seconds' => 3600,
        ],
        'password_reset_request' => [
            'max_attempts' => 3,
            'decay_seconds' => 3600,
        ],
        'password_reset_complete' => [
            'max_attempts' => 5,
            'decay_seconds' => 3600,
        ],
        'private_auth_write' => [
            'max_attempts' => 120,
            'decay_seconds' => 60,
        ],
        'private_auth_read' => [
            'max_attempts' => 300,
            'decay_seconds' => 60,
        ],
    ],

    'rate_limit_hmac_key' => env('AUTH_RATE_LIMIT_HMAC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    */

    'email_verification' => [
        'frontend_base_url' => env('APP_URL', 'https://app.localhost'),
        'path' => '/verify-email',
        'token_ttl_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset
    |--------------------------------------------------------------------------
    */

    'password_reset' => [
        'frontend_base_url' => env('APP_URL', 'https://app.localhost'),
        'frontend_path' => '/reset-password',
        'absolute_ttl_seconds' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dummy password hash (timing mitigation for missing users)
    |--------------------------------------------------------------------------
    |
    | Precomputed Argon2id hash that never matches a real login password.
    | Used so PasswordHasher::verify always runs when the user is absent.
    |
    */

    'dummy_password_hash' => env(
        'AUTH_DUMMY_PASSWORD_HASH',
        '$argon2id$v=19$m=65536,t=4,p=1$dW1hLjZzYkNaNFIyTWp1TQ$8TR1CU+8gjJX9mVCTV9Z+l4qRNOuqxQzZQhXRvLH2q4',
    ),

];
