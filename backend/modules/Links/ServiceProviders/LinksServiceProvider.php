<?php

declare(strict_types=1);

namespace Modules\Links\ServiceProviders;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;

final class LinksServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ShortLinkIdGenerator::class => Uuid7ShortLinkIdGenerator::class,
        LinkDestinationVersionIdGenerator::class => Uuid7LinkDestinationVersionIdGenerator::class,
        DestinationCipher::class => Aes256GcmDestinationCipher::class,
    ];

    public function register(): void
    {
        $this->app->singleton(DestinationKeyring::class, fn (): DestinationKeyring => DestinationKeyring::fromConfig(
            config('links.destination'),
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
