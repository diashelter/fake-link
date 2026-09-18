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
use Modules\Links\Infrastructure\Telemetry\LinkCreationMetrics;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ThrottleLinkCreation
{
    public function __construct(
        private readonly Application $app,
        private readonly LinkRateLimitKeyFactory $keyFactory,
        private readonly LinkErrorResponseFactory $errorResponses,
        private readonly LinkCreationMetrics $metrics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);
        $key = $this->keyFactory->forLinkCreation($principal->userId());
        $maxAttempts = (int) config('links.rate_limits.create.max_attempts', 60);
        $decaySeconds = (int) config('links.rate_limits.create.decay_seconds', 60);

        try {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $this->metrics->recordFailure(LinkCreationMetrics::REASON_RATE_LIMITED);

                return $this->errorResponses->rateLimitExceeded(
                    retryAfter: RateLimiter::availableIn($key),
                );
            }

            RateLimiter::hit($key, $decaySeconds);
        } catch (Throwable) {
            // Fail-open: Redis/cache outage must not block link creation.
            $this->metrics->recordFailure(LinkCreationMetrics::REASON_INFRASTRUCTURE);
            Log::warning('links.rate_limit.driver_unavailable', [
                'limiter' => 'links.create',
            ]);
        }

        return $next($request);
    }
}
