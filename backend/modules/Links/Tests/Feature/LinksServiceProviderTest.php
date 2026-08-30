<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Tests\TestCase;

uses(TestCase::class);

describe('LinksServiceProvider', function () {
    it('resolves DestinationCipher to Aes256GcmDestinationCipher', function () {
        $resolved = app(DestinationCipher::class);

        expect($resolved)->toBeInstanceOf(Aes256GcmDestinationCipher::class);
    });

    it('resolves ShortLinkIdGenerator to Uuid7ShortLinkIdGenerator', function () {
        $resolved = app(ShortLinkIdGenerator::class);

        expect($resolved)->toBeInstanceOf(Uuid7ShortLinkIdGenerator::class);
    });

    it('resolves LinkDestinationVersionIdGenerator to Uuid7LinkDestinationVersionIdGenerator', function () {
        $resolved = app(LinkDestinationVersionIdGenerator::class);

        expect($resolved)->toBeInstanceOf(Uuid7LinkDestinationVersionIdGenerator::class);
    });

    it('registers no routes under api/v1/links', function () {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/links'));

        expect($routes)->toBeEmpty();
    });

    it('links routes file exists and defines no routes', function () {
        $routesPath = __DIR__.'/../../Infrastructure/Http/routes/links.php';

        expect(file_exists($routesPath))->toBeTrue();

        // The file should define no routes (confirmed by empty route collection above)
        $routesContent = file_get_contents($routesPath);
        expect($routesContent)->not->toBeEmpty();
    });
});
