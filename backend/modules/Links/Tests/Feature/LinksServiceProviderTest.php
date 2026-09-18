<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Contracts\Services\Clock;
use Modules\Links\Contracts\Services\CursorCodec;
use Modules\Links\Contracts\Services\CursorSigningKey;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Contracts\Services\ReservedSlugs;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\ConfigCursorSigningKey;
use Modules\Links\Infrastructure\Crypto\ConfigETagSigningKey;
use Modules\Links\Infrastructure\Http\Controllers\CreateLinkController;
use Modules\Links\Infrastructure\Http\Controllers\GetLinkController;
use Modules\Links\Infrastructure\Http\Controllers\ListLinksController;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Pagination\HmacCursorCodec;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentDestinationVersionRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentLinkQueryRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\Infrastructure\Slug\CsprngSlugSource;
use Modules\Links\Infrastructure\Time\SystemClock;
use Modules\Links\UseCases\CreateLink;
use Modules\Links\UseCases\GetLink;
use Modules\Links\UseCases\ListLinks;
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

    it('resolves Clock to SystemClock', function () {
        expect(app(Clock::class))->toBeInstanceOf(SystemClock::class);
    });

    it('resolves CursorSigningKey to ConfigCursorSigningKey', function () {
        expect(app(CursorSigningKey::class))->toBeInstanceOf(ConfigCursorSigningKey::class);
    });

    it('resolves CursorCodec to HmacCursorCodec', function () {
        expect(app(CursorCodec::class))->toBeInstanceOf(HmacCursorCodec::class);
    });

    it('resolves LinkQueryRepository to EloquentLinkQueryRepository', function () {
        expect(app(LinkQueryRepository::class))->toBeInstanceOf(EloquentLinkQueryRepository::class);
    });

    it('resolves ListLinks and GetLink from the container without a service locator', function () {
        expect(app(ListLinks::class))->toBeInstanceOf(ListLinks::class)
            ->and(app(GetLink::class))->toBeInstanceOf(GetLink::class);
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

    it('registers GET api/v1/links to ListLinksController', function () {
        $get = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => in_array('GET', $route->methods(), true) && $route->uri() === 'api/v1/links',
        );

        expect($get)->not->toBeNull()
            ->and($get->getActionName())->toContain(ListLinksController::class)
            ->and($get->gatherMiddleware())->toContain('throttle.links.read');
    });

    it('registers GET api/v1/links/{link} to GetLinkController', function () {
        $get = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => in_array('GET', $route->methods(), true) && $route->uri() === 'api/v1/links/{link}',
        );

        expect($get)->not->toBeNull()
            ->and($get->getActionName())->toContain(GetLinkController::class)
            ->and($get->gatherMiddleware())->toContain('throttle.links.read');
    });
});
