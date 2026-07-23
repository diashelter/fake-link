<?php

it('returns ok status from health endpoint', function () {
    $response = $this->get('/health');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('returns ok status from api v1 health endpoint', function () {
    $response = $this->get('/api/v1/health');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('does not set a session cookie on the api v1 health endpoint', function () {
    $response = $this->get('/api/v1/health');

    $response->assertOk();

    $setCookie = $response->headers->get('Set-Cookie') ?? '';

    expect($setCookie)->not->toContain(config('session.cookie'));
});
