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
use Modules\Auth\Infrastructure\Http\Middleware\ThrottlePrivateAuthRead;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Tests\TestCase;

uses(TestCase::class);

function bindPrivateAuthReadPrincipal(AuthTokenId $tokenId): AuthenticatedPrincipal
{
    $principal = new AuthenticatedPrincipalRecord(
        userId: UserId::fromString('0190dddd-5678-7abc-89ab-cdef01234567'),
        userStatus: UserStatus::Active,
        tokenKind: TokenKind::Session,
        tokenId: $tokenId,
        expiresAt: new DateTimeImmutable('2026-07-28T00:00:00+00:00'),
    );

    app()->instance(AuthenticatedPrincipal::class, $principal);

    return $principal;
}

describe('ThrottlePrivateAuthRead', function () {
    beforeEach(function () {
        $this->tokenId = AuthTokenId::fromString('0190eeee-5678-7abc-89ab-cdef01234567');
        $this->keyFactory = new HmacRateLimitKeyFactory;
        $this->key = $this->keyFactory->forPrivateAuthRead($this->tokenId);
        RateLimiter::clear($this->key);
        bindPrivateAuthReadPrincipal($this->tokenId);

        $this->middleware = new ThrottlePrivateAuthRead(
            app(Application::class),
            $this->keyFactory,
            new AuthErrorResponseFactory,
        );
    });

    afterEach(function () {
        RateLimiter::clear($this->key);
    });

    it('allows the first 300 requests and returns 429 with Retry-After on the 301st', function () {
        $request = Request::create('/api/v1/me', 'GET');
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true], 200);

        for ($attempt = 1; $attempt <= 300; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(200);
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

    it('increments the rate limit even when the next handler returns an error status', function () {
        $request = Request::create('/api/v1/me', 'GET');
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['code' => 'INTERNAL_ERROR'], 500);

        for ($attempt = 1; $attempt <= 300; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(500);
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
