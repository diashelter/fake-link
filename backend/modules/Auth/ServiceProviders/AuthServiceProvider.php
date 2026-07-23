<?php

declare(strict_types=1);

namespace Modules\Auth\ServiceProviders;

use Illuminate\Support\ServiceProvider;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\UserIdGenerator;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        UserRepository::class => EloquentUserRepository::class,
        PasswordHasher::class => LaravelPasswordHasher::class,
        UserIdGenerator::class => Uuid7UserIdGenerator::class,
    ];

    public function register(): void
    {
        $this->app->singleton(UserMapper::class);
    }

    public function boot(): void
    {
        //
    }
}
