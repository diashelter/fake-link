<?php

declare(strict_types=1);

namespace Modules\Auth\ServiceProviders;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Contracts\Repositories\EmailActionTokenRepository;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\AuthTokenIdGenerator;
use Modules\Auth\Contracts\Services\EmailActionTokenIdGenerator;
use Modules\Auth\Contracts\Services\InviteAllowlist;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\QueueEmailVerification;
use Modules\Auth\Contracts\Services\QueuePasswordReset;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Contracts\Services\UserIdGenerator;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\Services\PasswordPolicy;
use Modules\Auth\Infrastructure\Allowlist\JsonFileInviteAllowlist;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Http\Middleware\AuthenticateBearer;
use Modules\Auth\Infrastructure\Http\Middleware\RequireTokenKind;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthValidationResponseFactory;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7EmailActionTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Notifications\LaravelQueueEmailVerification;
use Modules\Auth\Infrastructure\Notifications\LaravelQueuePasswordReset;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentEmailActionTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\UseCases\ChangePassword;
use Modules\Auth\UseCases\GetCurrentUser;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\IssueEmailVerificationToken;
use Modules\Auth\UseCases\IssuePasswordResetToken;
use Modules\Auth\UseCases\LoginUser;
use Modules\Auth\UseCases\LogoutAllSessions;
use Modules\Auth\UseCases\LogoutCurrentToken;
use Modules\Auth\UseCases\RegisterUser;
use Modules\Auth\UseCases\RequestPasswordReset;
use Modules\Auth\UseCases\ResendEmailVerification;
use Modules\Auth\UseCases\ResetPassword;
use Modules\Auth\UseCases\RevokeAllUserTokens;
use Modules\Auth\UseCases\RevokeAuthToken;
use Modules\Auth\UseCases\UpdateCurrentUser;
use Modules\Auth\UseCases\ValidateAuthToken;
use Modules\Auth\UseCases\VerifyUserEmail;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        UserRepository::class => EloquentUserRepository::class,
        PasswordHasher::class => LaravelPasswordHasher::class,
        UserIdGenerator::class => Uuid7UserIdGenerator::class,
        AuthTokenRepository::class => EloquentAuthTokenRepository::class,
        TokenHasher::class => Sha256TokenHasher::class,
        AuthTokenIdGenerator::class => Uuid7AuthTokenIdGenerator::class,
        EmailActionTokenRepository::class => EloquentEmailActionTokenRepository::class,
        EmailActionTokenIdGenerator::class => Uuid7EmailActionTokenIdGenerator::class,
        QueueEmailVerification::class => LaravelQueueEmailVerification::class,
        QueuePasswordReset::class => LaravelQueuePasswordReset::class,
    ];

    public function register(): void
    {
        $this->app->singleton(UserMapper::class);
        $this->app->singleton(AuthTokenMapper::class);
        $this->app->singleton(EmailActionTokenMapper::class);
        $this->app->singleton(BearerTokenGenerator::class);
        $this->app->singleton(PasswordPolicy::class);
        $this->app->singleton(AuthErrorResponseFactory::class);
        $this->app->singleton(AuthResponseFactory::class);
        $this->app->singleton(AuthValidationResponseFactory::class);
        $this->app->singleton(InviteAllowlist::class, fn (): InviteAllowlist => new JsonFileInviteAllowlist);
        $this->app->singleton(IssueAuthToken::class);
        $this->app->singleton(IssueEmailVerificationToken::class);
        $this->app->singleton(IssuePasswordResetToken::class);
        $this->app->singleton(VerifyUserEmail::class);
        $this->app->singleton(ResendEmailVerification::class);
        $this->app->singleton(RequestPasswordReset::class);
        $this->app->singleton(ResetPassword::class);
        $this->app->singleton(ChangePassword::class);
        $this->app->singleton(LogoutCurrentToken::class);
        $this->app->singleton(LogoutAllSessions::class);
        $this->app->singleton(GetCurrentUser::class);
        $this->app->singleton(UpdateCurrentUser::class);
        $this->app->singleton(ValidateAuthToken::class);
        $this->app->singleton(RevokeAuthToken::class);
        $this->app->singleton(RevokeAllUserTokens::class);
        $this->app->singleton(RegisterUser::class);
        $this->app->singleton(LoginUser::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'auth');

        $this->app->bind(AuthenticateBearer::class);
        $this->app->bind(RequireTokenKind::class);

        Route::prefix('api/v1/auth')
            ->middleware('api')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../Infrastructure/Http/routes/auth.php');
            });

        Route::prefix('api/v1')
            ->middleware('api')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../Infrastructure/Http/routes/me.php');
            });
    }
}
