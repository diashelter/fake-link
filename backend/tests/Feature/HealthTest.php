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
