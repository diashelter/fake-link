<?php

declare(strict_types=1);

use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\UserIdGenerator;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\ServiceProviders\AuthServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

describe('AuthServiceProvider', function () {
    it('is registered in bootstrap providers', function () {
        /** @var list<class-string> $providers */
        $providers = require base_path('bootstrap/providers.php');

        expect($providers)->toContain(AuthServiceProvider::class);
    });

    it('binds auth module contracts to concrete implementations', function () {
        expect(app(UserRepository::class))->toBeInstanceOf(EloquentUserRepository::class)
            ->and(app(PasswordHasher::class))->toBeInstanceOf(LaravelPasswordHasher::class)
            ->and(app(UserIdGenerator::class))->toBeInstanceOf(Uuid7UserIdGenerator::class);
    });
});
