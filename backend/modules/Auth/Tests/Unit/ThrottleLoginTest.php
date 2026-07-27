<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleLogin;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Tests\TestCase;

uses(TestCase::class);

describe('ThrottleLogin', function () {
    beforeEach(function () {
        $this->ip = '203.0.113.'.random_int(1, 254);
        $this->email = 'login-throttle-'.random_int(1000, 9999).'@example.com';
        $this->keyFactory = new HmacRateLimitKeyFactory;
        $this->keyEmailIp = $this->keyFactory->forLoginEmailIp($this->ip, $this->email);
        $this->keyIp = $this->keyFactory->forLoginIp($this->ip);
        RateLimiter::clear($this->keyEmailIp);
        RateLimiter::clear($this->keyIp);

        $this->middleware = new ThrottleLogin(
            $this->keyFactory,
            new AuthErrorResponseFactory,
        );
    });

    afterEach(function () {
        RateLimiter::clear($this->keyEmailIp);
        RateLimiter::clear($this->keyIp);
    });

    it('allows the first five email-ip requests and returns 429 with Retry-After on the sixth', function () {
        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => $this->email,
            'password' => 'whatever',
        ], server: [
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

    it('returns 429 on the 31st request from the same IP across distinct emails', function () {
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true], 200);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $email = sprintf('ip-limit-%d@example.com', $attempt);
            RateLimiter::clear($this->keyFactory->forLoginEmailIp($this->ip, $email));

            $request = Request::create('/api/v1/auth/login', 'POST', [
                'email' => $email,
                'password' => 'whatever',
            ], server: [
                'REMOTE_ADDR' => $this->ip,
            ]);

            $response = $this->middleware->handle($request, $next);

            expect($response->getStatusCode())->toBe(200);
        }

        $email = 'ip-limit-31@example.com';
        RateLimiter::clear($this->keyFactory->forLoginEmailIp($this->ip, $email));
        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => $email,
            'password' => 'whatever',
        ], server: [
            'REMOTE_ADDR' => $this->ip,
        ]);

        $limited = $this->middleware->handle($request, $next);

        expect($limited->getStatusCode())->toBe(429)
            ->and($limited->headers->get('Retry-After'))->not->toBeNull()
            ->and($limited->getData(true)['code'])->toBe('RATE_LIMIT_EXCEEDED');
    });

    it('hits both rate limit keys before the next handler so validation failures count', function () {
        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => $this->email,
            'password' => 'whatever',
        ], server: [
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

        expect($invocations)->toBe(5)
            ->and(RateLimiter::attempts($this->keyEmailIp))->toBe(5)
            ->and(RateLimiter::attempts($this->keyIp))->toBe(5);

        $limited = $this->middleware->handle($request, $next);

        expect($limited->getStatusCode())->toBe(429)
            ->and($invocations)->toBe(5);
    });

    it('uses the _invalid_ sentinel for syntactically invalid emails', function () {
        $sentinelKey = $this->keyFactory->forLoginEmailIp($this->ip, '_invalid_');
        RateLimiter::clear($sentinelKey);

        $request = Request::create('/api/v1/auth/login', 'POST', [
            'email' => 'not-an-email',
            'password' => 'whatever',
        ], server: [
            'REMOTE_ADDR' => $this->ip,
        ]);

        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true], 200);

        $this->middleware->handle($request, $next);

        expect(RateLimiter::attempts($sentinelKey))->toBe(1);

        RateLimiter::clear($sentinelKey);
    });
});
