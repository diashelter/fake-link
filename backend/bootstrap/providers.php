<?php

use App\Providers\AppServiceProvider;
use Modules\Auth\ServiceProviders\AuthServiceProvider;
use Modules\Links\ServiceProviders\LinksServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    LinksServiceProvider::class,
];
