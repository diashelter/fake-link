<?php

use Illuminate\Support\Facades\Route;

Route::get('/v1/health', function () {
    return response()->json(['status' => 'ok']);
});
