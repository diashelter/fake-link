<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Redirects\ServiceProviders\RedirectsServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

describe('RedirectsServiceProvider', function () {
    it('is registered in the application and boots without error', function () {
        $providers = app()->getLoadedProviders();

        expect(array_key_exists(RedirectsServiceProvider::class, $providers))->toBeTrue();
    });

    it('defines no new routes under any redirects path', function () {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'redirects'));

        expect($routes)->toBeEmpty();
    });

    it('placeholder web routes from web.php are still registered: GET /', function () {
        $response = $this->get('/');

        expect($response->status())->toBe(302);
    });

    it('placeholder web routes from web.php are still registered: GET /robots.txt', function () {
        $response = $this->get('/robots.txt');

        expect($response->status())->toBe(200);
    });

    it('placeholder web routes from web.php are still registered: GET /{slug} returns 404', function () {
        $response = $this->get('/some-slug');

        expect($response->status())->toBe(404);
    });

    it('placeholder GET /{slug} responds to HEAD with no body', function () {
        $response = $this->call('HEAD', '/some-slug');

        expect($response->status())->toBe(404)
            ->and($response->getContent())->toBe('');
    });
});
