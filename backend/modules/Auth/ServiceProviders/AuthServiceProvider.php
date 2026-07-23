<?php

declare(strict_types=1);

namespace Modules\Auth\ServiceProviders;

use Illuminate\Support\ServiceProvider;
use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\AuthTokenIdGenerator;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Contracts\Services\UserIdGenerator;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Http\Middleware\AuthenticateBearer;
use Modules\Auth\Infrastructure\Http\Middleware\RequireTokenKind;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\RevokeAllUserTokens;
use Modules\Auth\UseCases\RevokeAuthToken;
use Modules\Auth\UseCases\ValidateAuthToken;

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
    ];

    public function register(): void
    {
        $this->app->singleton(UserMapper::class);
        $this->app->singleton(AuthTokenMapper::class);
        $this->app->singleton(BearerTokenGenerator::class);
        $this->app->singleton(AuthErrorResponseFactory::class);
        $this->app->singleton(IssueAuthToken::class);
        $this->app->singleton(ValidateAuthToken::class);
        $this->app->singleton(RevokeAuthToken::class);
        $this->app->singleton(RevokeAllUserTokens::class);
    }

    public function boot(): void
    {
        $this->app->bind(AuthenticateBearer::class);
        $this->app->bind(RequireTokenKind::class);
    }
}
