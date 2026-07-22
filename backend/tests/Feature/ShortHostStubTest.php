<?php

it('redirects short host root to app url', function () {
    $response = $this->get('/');

    $response->assertRedirect(config('app.url'));
});

it('serves robots disallowing all crawlers', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Disallow: /');
});

it('returns not found for slug stub', function () {
    $response = $this->get('/demo-link');

    $response->assertNotFound();
});
