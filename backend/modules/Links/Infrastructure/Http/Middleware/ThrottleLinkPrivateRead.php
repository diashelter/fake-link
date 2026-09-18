<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Links\Infrastructure\Http\Responses\LinkErrorResponseFactory;
use Modules\Links\Infrastructure\RateLimit\LinkRateLimitKeyFactory;
use Modules\Links\Infrastructure\Telemetry\LinkQueryMetrics;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ThrottleLinkPrivateRead
{
    public function __construct(
        private readonly Application $app,
        private readonly LinkRateLimitKeyFactory $keyFactory,
        private readonly LinkErrorResponseFactory $errorResponses,
        private readonly LinkQueryMetrics $metrics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);
        $key = $this->keyFactory->forPrivateRead($principal->tokenId());
        $maxAttempts = (int) config('links.rate_limits.private_read.max_attempts', 300);
        $decaySeconds = (int) config('links.rate_limits.private_read.decay_seconds', 60);
        $operation = $request->route('link') === null
            ? LinkQueryMetrics::OPERATION_LIST
            : LinkQueryMetrics::OPERATION_DETAIL;

        try {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $this->metrics->recordFailure($operation, LinkQueryMetrics::REASON_RATE_LIMITED);

                return $this->errorResponses->rateLimitExceeded(
                    retryAfter: RateLimiter::availableIn($key),
                );
            }

            RateLimiter::hit($key, $decaySeconds);
        } catch (Throwable) {
            $this->metrics->recordFailure($operation, LinkQueryMetrics::REASON_INFRASTRUCTURE);
            Log::warning('links.rate_limit.driver_unavailable', [
                'limiter' => 'links.private_read',
            ]);
        }

        return $next($request);
    }
}
