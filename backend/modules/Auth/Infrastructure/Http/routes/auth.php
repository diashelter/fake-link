<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Infrastructure\Http\Controllers\LoginUserController;
use Modules\Auth\Infrastructure\Http\Controllers\RegisterUserController;
use Modules\Auth\Infrastructure\Http\Controllers\ResendEmailVerificationController;
use Modules\Auth\Infrastructure\Http\Controllers\VerifyEmailController;

Route::post('/register', RegisterUserController::class)
    ->middleware('throttle.registration');

Route::post('/login', LoginUserController::class)
    ->middleware('throttle.login');

Route::post('/email/verify', VerifyEmailController::class)
    ->middleware([
        'auth.bearer',
        'token.kind:verification',
        'throttle.email_verification.verify',
    ]);

Route::post('/email/verification-notification', ResendEmailVerificationController::class)
    ->middleware([
        'auth.bearer',
        'token.kind:verification',
        'throttle.email_verification.resend',
    ]);
