<?php

use App\Providers\AppServiceProvider;
use Modules\Auth\ServiceProviders\AuthServiceProvider;
use Modules\Links\ServiceProviders\LinksServiceProvider;
use Modules\Redirects\ServiceProviders\RedirectsServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    LinksServiceProvider::class,
    RedirectsServiceProvider::class,
];
