<?php

it('returns ok status from health endpoint', function () {
    $response = $this->get('/health');

    $response->assertOk()
        ->assertJson(['status' => 'ok']);
});
