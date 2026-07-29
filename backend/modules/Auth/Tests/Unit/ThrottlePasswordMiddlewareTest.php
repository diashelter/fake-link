<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottlePasswordReset;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottlePasswordResetRequest;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottlePrivateAuthWrite;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Tests\TestCase;

uses(TestCase::class);

function bindPrivateAuthWritePrincipal(UserId $userId): AuthenticatedPrincipal
{
    $principal = new AuthenticatedPrincipalRecord(
        userId: $userId,
        userStatus: UserStatus::Active,
        tokenKind: TokenKind::Session,
        tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef01234567'),
        expiresAt: new DateTimeImmutable('2026-07-28T00:00:00+00:00'),
    );

    app()->instance(AuthenticatedPrincipal::class, $principal);

    return $principal;
}

describe('ThrottlePasswordResetRequest', function () {
    beforeEach(function () {
        $this->keyFactory = new HmacRateLimitKeyFactory;
        $this->ip = '203.0.113.90';
        $this->email = 'reset.throttle@example.com';
        $this->key = $this->keyFactory->forPasswordResetRequest($this->ip, $this->email);
        RateLimiter::clear($this->key);

        $this->middleware = new ThrottlePasswordResetRequest(
            $this->keyFactory,
            new AuthErrorResponseFactory,
        );
    });

    afterEach(function () {
        RateLimiter::clear($this->key);
    });

    it('allows the first three requests and returns 429 with Retry-After on the fourth', function () {
        $request = Request::create(
            '/api/v1/auth/password/reset-request',
            'POST',
            ['email' => $this->email],
            server: ['REMOTE_ADDR' => $this->ip],
        );
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true], 202);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(202);
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

    it('increments the rate limit even when the next handler returns 422', function () {
        $request = Request::create(
            '/api/v1/auth/password/reset-request',
            'POST',
            ['email' => $this->email],
            server: ['REMOTE_ADDR' => $this->ip],
        );
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['code' => 'VALIDATION_FAILED'], 422);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(422);
        }

        $limited = $this->middleware->handle($request, $next);

        expect($limited->getStatusCode())->toBe(429)
            ->and($limited->headers->get('Retry-After'))->not->toBeNull()
            ->and($limited->getData(true))->toMatchArray([
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests.',
            ]);
    });
});

describe('ThrottlePasswordReset', function () {
    beforeEach(function () {
        $this->keyFactory = new HmacRateLimitKeyFactory;
        $this->ip = '203.0.113.91';
        $this->token = 'reset-token-value';
        $this->key = $this->keyFactory->forPasswordResetComplete($this->ip, hash('sha256', $this->token));
        RateLimiter::clear($this->key);

        $this->middleware = new ThrottlePasswordReset(
            $this->keyFactory,
            new AuthErrorResponseFactory,
        );
    });

    afterEach(function () {
        RateLimiter::clear($this->key);
    });

    it('allows the first five requests and returns 429 with Retry-After on the sixth', function () {
        $request = Request::create(
            '/api/v1/auth/password/reset',
            'POST',
            ['token' => $this->token],
            server: ['REMOTE_ADDR' => $this->ip],
        );
        $next = static fn (Request $request): JsonResponse => new JsonResponse(null, 204);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(204);
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
});

describe('ThrottlePrivateAuthWrite', function () {
    beforeEach(function () {
        $this->userId = UserId::fromString('0190cccc-5678-7abc-89ab-cdef01234567');
        $this->keyFactory = new HmacRateLimitKeyFactory;
        $this->key = $this->keyFactory->forPrivateAuthWrite($this->userId);
        RateLimiter::clear($this->key);
        bindPrivateAuthWritePrincipal($this->userId);

        $this->middleware = new ThrottlePrivateAuthWrite(
            app(Application::class),
            $this->keyFactory,
            new AuthErrorResponseFactory,
        );
    });

    afterEach(function () {
        RateLimiter::clear($this->key);
    });

    it('allows the first 120 requests and returns 429 with Retry-After on the 121st', function () {
        $request = Request::create('/api/v1/auth/password/change', 'POST');
        $next = static fn (Request $request): JsonResponse => new JsonResponse(null, 204);

        for ($attempt = 1; $attempt <= 120; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(204);
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
});
