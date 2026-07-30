<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Infrastructure\Http\Controllers\GetCurrentUserController;
use Modules\Auth\Infrastructure\Http\Controllers\UpdateCurrentUserController;

Route::get('/me', GetCurrentUserController::class)
    ->middleware([
        'auth.bearer',
        'throttle.private_auth.read',
    ]);

Route::patch('/me', UpdateCurrentUserController::class)
    ->middleware([
        'auth.bearer',
        'token.kind:session',
        'throttle.private_auth.write',
    ]);
