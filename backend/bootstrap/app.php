<?php

use App\Http\Middleware\RejectMalformedJson;
use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Auth\Infrastructure\Http\Middleware\AuthenticateBearer;
use Modules\Auth\Infrastructure\Http\Middleware\RequireTokenKind;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleEmailVerificationResend;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleEmailVerificationVerify;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleLogin;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottlePasswordReset;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottlePasswordResetRequest;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottlePrivateAuthWrite;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleRegistration;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            RejectMalformedJson::class,
        ]);

        $middleware->alias([
            'auth.bearer' => AuthenticateBearer::class,
            'token.kind' => RequireTokenKind::class,
            'throttle.registration' => ThrottleRegistration::class,
            'throttle.login' => ThrottleLogin::class,
            'throttle.email_verification.resend' => ThrottleEmailVerificationResend::class,
            'throttle.email_verification.verify' => ThrottleEmailVerificationVerify::class,
            'throttle.password_reset.request' => ThrottlePasswordResetRequest::class,
            'throttle.password_reset.complete' => ThrottlePasswordReset::class,
            'throttle.private_auth.write' => ThrottlePrivateAuthWrite::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => true,
        );

        $exceptions->render(function (BadRequestHttpException $exception, Request $request) {
            return ApiResponse::malformedRequest();
        });
    })->create();
