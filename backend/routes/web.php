<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/api/v1/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/robots.txt', function () {
    return response("User-agent: *\nDisallow: /\n", 200, [
        'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
});

Route::get('/', function () {
    return redirect(config('app.url'), 302);
});

Route::get('/{slug}', function (string $slug) {
    return response()->noContent(404);
})->where('slug', '[a-z0-9-]+');
