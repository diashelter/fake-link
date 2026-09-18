<?php

declare(strict_types=1);

namespace Modules\Links\ServiceProviders;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Contracts\Services\ReservedSlugs;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\ConfigETagSigningKey;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\LinkDestinationVersionMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\ShortLinkMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentDestinationVersionRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\Infrastructure\Slug\CsprngSlugSource;
use Modules\Links\Infrastructure\Telemetry\LinkCreationMetrics;
use Modules\Links\UseCases\CreateLink;
use Modules\Links\UseCases\ReserveSlug;
use Modules\Links\UseCases\SealDestinationUrl;

final class LinksServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ShortLinkIdGenerator::class => Uuid7ShortLinkIdGenerator::class,
        LinkDestinationVersionIdGenerator::class => Uuid7LinkDestinationVersionIdGenerator::class,
        DestinationCipher::class => Aes256GcmDestinationCipher::class,
        ReservedSlugs::class => ConfigReservedSlugs::class,
        RandomSlugSource::class => CsprngSlugSource::class,
        SlugReservationRepository::class => EloquentSlugReservationRepository::class,
        ShortLinkRepository::class => EloquentShortLinkRepository::class,
        DestinationVersionRepository::class => EloquentDestinationVersionRepository::class,
    ];

    public function register(): void
    {
        $this->app->singleton(LinkCreationMetrics::class);

        $this->app->singleton(DestinationKeyring::class, fn (): DestinationKeyring => DestinationKeyring::fromConfig(
            config('links.destination'),
        ));

        $this->app->singleton(PublicHostClassifier::class, fn (): PublicHostClassifier => new PublicHostClassifier(
            config('links.destination.self_hosts'),
        ));

        $this->app->singleton(ETagSigningKey::class, fn (): ETagSigningKey => new ConfigETagSigningKey(
            (string) config('links.etag_hmac_key'),
        ));

        $this->app->singleton(LinkETag::class, fn (Application $app): LinkETag => new LinkETag(
            $app->make(ETagSigningKey::class),
        ));

        $this->app->singleton(EffectiveStatus::class);

        $this->app->bind(SlugGenerator::class, fn (Application $app): SlugGenerator => new SlugGenerator(
            $app->make(RandomSlugSource::class),
            $app->make(SlugPolicy::class),
            (int) config('links.slug.length'),
            (int) config('links.slug.max_denylist_discards'),
        ));

        $this->app->bind(ReserveSlug::class, fn (Application $app): ReserveSlug => new ReserveSlug(
            $app->make(SlugPolicy::class),
            $app->make(SlugGenerator::class),
            $app->make(SlugReservationRepository::class),
            (int) config('links.slug.max_collision_attempts'),
        ));

        $this->app->bind(SealDestinationUrl::class, fn (Application $app): SealDestinationUrl => new SealDestinationUrl(
            $app->make(PublicHostClassifier::class),
            $app->make(DestinationCipher::class),
        ));

        $this->app->bind(CreateLink::class, fn (Application $app): CreateLink => new CreateLink(
            $app->make(SealDestinationUrl::class),
            $app->make(PublicHostClassifier::class),
            $app->make(ReserveSlug::class),
            $app->make(ShortLinkRepository::class),
            $app->make(DestinationVersionRepository::class),
            $app->make(EffectiveStatus::class),
        ));

        $this->app->bind(ShortLinkMapper::class);
        $this->app->bind(LinkDestinationVersionMapper::class);
    }

    public function boot(): void
    {
        Route::prefix('api/v1/links')
            ->middleware('api')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../Infrastructure/Http/routes/links.php');
            });
    }
}
