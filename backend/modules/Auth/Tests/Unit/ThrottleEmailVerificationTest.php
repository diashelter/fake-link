<?php

declare(strict_types=1);

use DateTimeImmutable;
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
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleEmailVerificationResend;
use Modules\Auth\Infrastructure\Http\Middleware\ThrottleEmailVerificationVerify;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Tests\TestCase;

uses(TestCase::class);

function bindEmailVerificationPrincipal(UserId $userId): AuthenticatedPrincipal
{
    $principal = new AuthenticatedPrincipalRecord(
        userId: $userId,
        userStatus: UserStatus::PendingVerification,
        tokenKind: TokenKind::Verification,
        tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef01234567'),
        expiresAt: new DateTimeImmutable('2026-07-28T00:00:00+00:00'),
    );

    app()->instance(AuthenticatedPrincipal::class, $principal);

    return $principal;
}

describe('ThrottleEmailVerificationResend', function () {
    beforeEach(function () {
        $this->userId = UserId::fromString('0190aaaa-5678-7abc-89ab-cdef01234567');
        $this->keyFactory = new HmacRateLimitKeyFactory;
        $this->key = $this->keyFactory->forEmailVerificationResend($this->userId);
        RateLimiter::clear($this->key);
        bindEmailVerificationPrincipal($this->userId);

        $this->middleware = new ThrottleEmailVerificationResend(
            app(Application::class),
            $this->keyFactory,
            new AuthErrorResponseFactory,
        );
    });

    afterEach(function () {
        RateLimiter::clear($this->key);
    });

    it('allows the first three requests and returns 429 with Retry-After on the fourth', function () {
        $request = Request::create('/api/v1/auth/email/verification-notification', 'POST');
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
});

describe('ThrottleEmailVerificationVerify', function () {
    beforeEach(function () {
        $this->userId = UserId::fromString('0190bbbb-5678-7abc-89ab-cdef01234567');
        $this->keyFactory = new HmacRateLimitKeyFactory;
        $this->key = $this->keyFactory->forEmailVerificationVerify($this->userId);
        RateLimiter::clear($this->key);
        bindEmailVerificationPrincipal($this->userId);

        $this->middleware = new ThrottleEmailVerificationVerify(
            app(Application::class),
            $this->keyFactory,
            new AuthErrorResponseFactory,
        );
    });

    afterEach(function () {
        RateLimiter::clear($this->key);
    });

    it('allows the first five requests and returns 429 with Retry-After on the sixth', function () {
        $request = Request::create('/api/v1/auth/email/verify', 'POST', ['token' => 'x']);
        $next = static fn (Request $request): JsonResponse => new JsonResponse(null, 204);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(204);
        }

        $limited = $this->middleware->handle($request, $next);

        expect($limited->getStatusCode())->toBe(429)
            ->and($limited->headers->get('Retry-After'))->not->toBeNull()
            ->and($limited->getData(true)['code'])->toBe('RATE_LIMIT_EXCEEDED');
    });

    it('hits the rate limiter before the next handler so failures count', function () {
        $request = Request::create('/api/v1/auth/email/verify', 'POST', ['token' => 'x']);
        $invocations = 0;
        $next = static function (Request $request) use (&$invocations): JsonResponse {
            $invocations++;

            return new JsonResponse(['code' => 'INVALID_VERIFICATION_TOKEN'], 403);
        };

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            expect($this->middleware->handle($request, $next)->getStatusCode())->toBe(403);
        }

        expect($invocations)->toBe(5)
            ->and(RateLimiter::attempts($this->key))->toBe(5);

        $limited = $this->middleware->handle($request, $next);

        expect($limited->getStatusCode())->toBe(429)
            ->and($invocations)->toBe(5);
    });
});
