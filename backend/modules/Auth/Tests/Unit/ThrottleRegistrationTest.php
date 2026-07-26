<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleRegistration;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Tests\TestCase;

uses(TestCase::class);

describe('ThrottleRegistration', function () {
    beforeEach(function () {
        $this->ip = '203.0.113.'.random_int(1, 254);
        $this->key = (new HmacRateLimitKeyFactory)->forRegistrationIp($this->ip);
        RateLimiter::clear($this->key);

        $this->middleware = new ThrottleRegistration(
            new HmacRateLimitKeyFactory,
            new AuthErrorResponseFactory,
        );
    });

    it('allows the first five requests and returns 429 with Retry-After on the sixth', function () {
        $request = Request::create('/api/v1/auth/register', 'POST', server: [
            'REMOTE_ADDR' => $this->ip,
        ]);

        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true], 200);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->middleware->handle($request, $next);

            expect($response->getStatusCode())->toBe(200);
        }

        $limited = $this->middleware->handle($request, $next);

        expect($limited)->toBeInstanceOf(JsonResponse::class)
            ->and($limited->getStatusCode())->toBe(429)
            ->and($limited->headers->get('Retry-After'))->not->toBeNull()
            ->and($limited->getData(true))->toMatchArray([
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests.',
            ]);
    });

    it('hits the rate limiter before the next handler so validation failures count', function () {
        $request = Request::create('/api/v1/auth/register', 'POST', server: [
            'REMOTE_ADDR' => $this->ip,
        ]);

        $invocations = 0;
        $next = static function (Request $request) use (&$invocations): JsonResponse {
            $invocations++;

            return new JsonResponse(['code' => 'VALIDATION_FAILED'], 422);
        };

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->middleware->handle($request, $next);

            expect($response->getStatusCode())->toBe(422);
        }

        expect($invocations)->toBe(5);

        $limited = $this->middleware->handle($request, $next);

        expect($limited->getStatusCode())->toBe(429)
            ->and($invocations)->toBe(5);
    });
});
