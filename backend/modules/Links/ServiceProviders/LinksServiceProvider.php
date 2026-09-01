<?php

declare(strict_types=1);

namespace Modules\Links\ServiceProviders;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Contracts\Services\ReservedSlugs;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\Infrastructure\Slug\CsprngSlugSource;
use Modules\Links\UseCases\ReserveSlug;

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
    ];

    public function register(): void
    {
        $this->app->singleton(DestinationKeyring::class, fn (): DestinationKeyring => DestinationKeyring::fromConfig(
            config('links.destination'),
        ));

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
