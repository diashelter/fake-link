<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleEmailVerificationVerify
{
    public function __construct(
        private readonly Application $app,
        private readonly HmacRateLimitKeyFactory $keyFactory,
        private readonly AuthErrorResponseFactory $errorResponses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);
        $key = $this->keyFactory->forEmailVerificationVerify($principal->userId());
        $maxAttempts = (int) config('auth.rate_limits.email_verification_verify.max_attempts', 5);
        $decaySeconds = (int) config('auth.rate_limits.email_verification_verify.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return $this->errorResponses->rateLimitExceeded(
                retryAfter: RateLimiter::availableIn($key),
            );
        }

        RateLimiter::hit($key, $decaySeconds);

        return $next($request);
    }
}
