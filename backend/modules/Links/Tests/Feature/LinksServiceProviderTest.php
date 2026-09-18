<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Contracts\Services\ReservedSlugs;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\ConfigETagSigningKey;
use Modules\Links\Infrastructure\Http\Controllers\CreateLinkController;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentDestinationVersionRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\Infrastructure\Slug\CsprngSlugSource;
use Modules\Links\UseCases\CreateLink;
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

    it('resolves ReservedSlugs to ConfigReservedSlugs', function () {
        expect(app(ReservedSlugs::class))->toBeInstanceOf(ConfigReservedSlugs::class);
    });

    it('resolves RandomSlugSource to CsprngSlugSource', function () {
        expect(app(RandomSlugSource::class))->toBeInstanceOf(CsprngSlugSource::class);
    });

    it('resolves SlugReservationRepository to EloquentSlugReservationRepository', function () {
        expect(app(SlugReservationRepository::class))->toBeInstanceOf(EloquentSlugReservationRepository::class);
    });

    it('resolves ShortLinkRepository to EloquentShortLinkRepository', function () {
        expect(app(ShortLinkRepository::class))->toBeInstanceOf(EloquentShortLinkRepository::class);
    });

    it('resolves DestinationVersionRepository to EloquentDestinationVersionRepository', function () {
        expect(app(DestinationVersionRepository::class))->toBeInstanceOf(EloquentDestinationVersionRepository::class);
    });

    it('resolves ETagSigningKey to ConfigETagSigningKey', function () {
        expect(app(ETagSigningKey::class))->toBeInstanceOf(ConfigETagSigningKey::class);
    });

    it('resolves CreateLink from the container', function () {
        expect(app(CreateLink::class))->toBeInstanceOf(CreateLink::class);
    });

    it('registers POST api/v1/links to CreateLinkController', function () {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/links'));

        expect($routes)->not->toBeEmpty();

        $post = $routes->first(
            fn ($route) => in_array('POST', $route->methods(), true) && $route->uri() === 'api/v1/links',
        );

        expect($post)->not->toBeNull()
            ->and($post->getActionName())->toContain(CreateLinkController::class);
    });
});
