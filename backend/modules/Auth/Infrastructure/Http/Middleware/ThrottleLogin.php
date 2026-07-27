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

final class ThrottleLogin
{
    public function __construct(
        private readonly HmacRateLimitKeyFactory $keyFactory,
        private readonly AuthErrorResponseFactory $errorResponses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        $emailKeyPart = $this->emailKeyPart($request);
        $keyEmailIp = $this->keyFactory->forLoginEmailIp($ip, $emailKeyPart);
        $keyIp = $this->keyFactory->forLoginIp($ip);

        $emailIpMax = (int) config('auth.rate_limits.login.email_ip.max_attempts', 5);
        $emailIpDecay = (int) config('auth.rate_limits.login.email_ip.decay_seconds', 60);
        $ipMax = (int) config('auth.rate_limits.login.ip.max_attempts', 30);
        $ipDecay = (int) config('auth.rate_limits.login.ip.decay_seconds', 60);

        $emailIpLimited = RateLimiter::tooManyAttempts($keyEmailIp, $emailIpMax);
        $ipLimited = RateLimiter::tooManyAttempts($keyIp, $ipMax);

        if ($emailIpLimited || $ipLimited) {
            $retryAfter = min(
                $emailIpLimited ? RateLimiter::availableIn($keyEmailIp) : PHP_INT_MAX,
                $ipLimited ? RateLimiter::availableIn($keyIp) : PHP_INT_MAX,
            );

            return $this->errorResponses->rateLimitExceeded(
                retryAfter: $retryAfter,
            );
        }

        RateLimiter::hit($keyEmailIp, $emailIpDecay);
        RateLimiter::hit($keyIp, $ipDecay);

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
