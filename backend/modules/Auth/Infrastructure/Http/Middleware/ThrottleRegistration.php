<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleRegistration
{
    public function __construct(
        private readonly HmacRateLimitKeyFactory $keyFactory,
        private readonly AuthErrorResponseFactory $errorResponses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->keyFactory->forRegistrationIp((string) $request->ip());
        $maxAttempts = (int) config('auth.rate_limits.registration.max_attempts', 5);
        $decaySeconds = (int) config('auth.rate_limits.registration.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return $this->errorResponses->rateLimitExceeded(
                retryAfter: RateLimiter::availableIn($key),
            );
        }

        RateLimiter::hit($key, $decaySeconds);

        return $next($request);
    }
}
