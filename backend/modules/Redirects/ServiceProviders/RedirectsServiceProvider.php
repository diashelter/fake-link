<?php

declare(strict_types=1);

namespace Modules\Redirects\ServiceProviders;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class RedirectsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../Infrastructure/Http/routes/redirects.php');
            });
    }
}
