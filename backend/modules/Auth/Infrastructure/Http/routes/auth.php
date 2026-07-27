<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Infrastructure\Http\Controllers\LoginUserController;
use Modules\Auth\Infrastructure\Http\Controllers\RegisterUserController;

Route::post('/register', RegisterUserController::class)
    ->middleware('throttle.registration');

Route::post('/login', LoginUserController::class)
    ->middleware('throttle.login');
