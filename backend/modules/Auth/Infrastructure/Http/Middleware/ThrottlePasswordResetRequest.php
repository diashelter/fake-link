<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ThrottlePasswordResetRequest
{
    public function __construct(
        private readonly HmacRateLimitKeyFactory $keyFactory,
        private readonly AuthErrorResponseFactory $errorResponses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        $key = $this->keyFactory->forPasswordResetRequest($ip, $this->emailKeyPart($request));
        $maxAttempts = (int) config('auth.rate_limits.password_reset_request.max_attempts', 3);
        $decaySeconds = (int) config('auth.rate_limits.password_reset_request.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return $this->errorResponses->rateLimitExceeded(
                retryAfter: RateLimiter::availableIn($key),
            );
        }

        RateLimiter::hit($key, $decaySeconds);

        return $next($request);
    }

    private function emailKeyPart(Request $request): string
    {
        $raw = $request->input('email');

        if (! is_string($raw)) {
            return '_invalid_';
        }

        try {
            return EmailAddress::fromString($raw)->value();
        } catch (Throwable) {
            return '_invalid_';
        }
    }
}
