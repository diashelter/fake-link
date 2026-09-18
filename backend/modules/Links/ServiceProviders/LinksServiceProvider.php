<?php

declare(strict_types=1);

namespace Modules\Links\ServiceProviders;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Repositories\IdempotencyKeyRepository;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Contracts\Services\CursorCodec;
use Modules\Links\Contracts\Services\CursorSigningKey;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Contracts\Services\IdempotencyHmacSecrets;
use Modules\Links\Contracts\Services\IdempotencySnapshotCipher;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Contracts\Services\ReservedSlugs;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Contracts\Services\TransactionManager;
use Modules\Links\Domain\Services\CanonicalCreateLinkCommand;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Infrastructure\Console\Commands\PruneExpiredIdempotencyKeys;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\Aes256GcmIdempotencySnapshotCipher;
use Modules\Links\Infrastructure\Crypto\ConfigCursorSigningKey;
use Modules\Links\Infrastructure\Crypto\ConfigETagSigningKey;
use Modules\Links\Infrastructure\Crypto\ConfigIdempotencyHmacSecrets;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Modules\Links\Infrastructure\Crypto\IdempotencyKeyring;
use Modules\Links\Infrastructure\Http\Responses\LinkCreationSnapshotFactory;
use Modules\Links\Infrastructure\Http\Responses\LinkResponseFactory;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Pagination\HmacCursorCodec;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\IdempotencyKeyMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\LinkDestinationVersionMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\ShortLinkMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentDestinationVersionRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentIdempotencyKeyRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Persistence\LaravelTransactionManager;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\Infrastructure\Slug\CsprngSlugSource;
use Modules\Links\Infrastructure\Telemetry\LinkCreationMetrics;
use Modules\Links\UseCases\CreateIdempotentLink;
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
        IdempotencySnapshotCipher::class => Aes256GcmIdempotencySnapshotCipher::class,
        TransactionManager::class => LaravelTransactionManager::class,
        ReservedSlugs::class => ConfigReservedSlugs::class,
        RandomSlugSource::class => CsprngSlugSource::class,
        SlugReservationRepository::class => EloquentSlugReservationRepository::class,
        ShortLinkRepository::class => EloquentShortLinkRepository::class,
        DestinationVersionRepository::class => EloquentDestinationVersionRepository::class,
        IdempotencyKeyRepository::class => EloquentIdempotencyKeyRepository::class,
    ];

    public function register(): void
    {
        $this->app->singleton(LinkCreationMetrics::class);

        $this->app->singleton(DestinationKeyring::class, fn (): DestinationKeyring => DestinationKeyring::fromConfig(
            config('links.destination'),
        ));

        $this->app->singleton(IdempotencyKeyring::class, fn (): IdempotencyKeyring => IdempotencyKeyring::fromConfig([
            'keyring' => (string) config('links.idempotency.keyring'),
            'active_key_id' => (string) config('links.idempotency.active_key_id'),
        ]));

        $this->app->singleton(IdempotencyHmacSecrets::class, fn (): IdempotencyHmacSecrets => new ConfigIdempotencyHmacSecrets(
            (string) config('links.idempotency.key_hash_hmac_key'),
            (string) config('links.idempotency.fingerprint_hmac_key'),
        ));

        $this->app->singleton(CanonicalCreateLinkCommand::class, fn (Application $app): CanonicalCreateLinkCommand => new CanonicalCreateLinkCommand(
            $app->make(IdempotencyHmacSecrets::class),
        ));

        $this->app->singleton(PublicHostClassifier::class, fn (): PublicHostClassifier => new PublicHostClassifier(
            config('links.destination.self_hosts'),
        ));

        $this->app->singleton(ETagSigningKey::class, fn (): ETagSigningKey => new ConfigETagSigningKey(
            (string) config('links.etag_hmac_key'),
        ));

        $this->app->singleton(CursorSigningKey::class, fn (): CursorSigningKey => new ConfigCursorSigningKey(
            (string) config('links.cursor_hmac_key'),
        ));

        $this->app->singleton(CursorCodec::class, fn (Application $app): CursorCodec => new HmacCursorCodec(
            $app->make(CursorSigningKey::class),
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
            $app->make(TransactionManager::class),
        ));

        $this->app->bind(LinkCreationSnapshotFactory::class, fn (Application $app): LinkCreationSnapshotFactory => new LinkCreationSnapshotFactory(
            $app->make(LinkETag::class),
        ));

        $this->app->bind(LinkResponseFactory::class, fn (Application $app): LinkResponseFactory => new LinkResponseFactory(
            $app->make(LinkCreationSnapshotFactory::class),
        ));

        $this->app->bind(CreateIdempotentLink::class, fn (Application $app): CreateIdempotentLink => new CreateIdempotentLink(
            $app->make(TransactionManager::class),
            $app->make(CreateLink::class),
            $app->make(IdempotencyKeyRepository::class),
            $app->make(CanonicalCreateLinkCommand::class),
            $app->make(IdempotencySnapshotCipher::class),
            $app->make(LinkCreationSnapshotFactory::class),
        ));

        $this->app->bind(ShortLinkMapper::class);
        $this->app->bind(LinkDestinationVersionMapper::class);
        $this->app->bind(IdempotencyKeyMapper::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneExpiredIdempotencyKeys::class,
            ]);
        }

        Route::prefix('api/v1/links')
            ->middleware('api')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../Infrastructure/Http/routes/links.php');
            });
    }
}
