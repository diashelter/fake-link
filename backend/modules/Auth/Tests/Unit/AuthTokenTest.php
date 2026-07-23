<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

describe('AuthToken', function () {
    it('reports expired when now is at or after expires_at', function () {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');

        $token = AuthToken::issue(
            id: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            tokenKind: TokenKind::Verification,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );

        expect($token->isExpiredAt(Carbon::parse('2026-01-01T23:59:59+00:00')))->toBeFalse()
            ->and($token->isExpiredAt(Carbon::parse('2026-01-02T00:00:00+00:00')))->toBeTrue()
            ->and($token->isExpiredAt(Carbon::parse('2026-01-02T01:00:00+00:00')))->toBeTrue();
    });

    it('uses created_at as idle reference when last_used_at is null', function () {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-01-10T00:00:00+00:00');

        $token = AuthToken::issue(
            id: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            tokenKind: TokenKind::Verification,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );

        expect($token->isIdleExpiredAt(Carbon::parse('2026-01-01T01:00:00+00:00')))->toBeFalse()
            ->and($token->isIdleExpiredAt(Carbon::parse('2026-01-01T01:00:01+00:00')))->toBeTrue();
    });

    it('uses last_used_at as idle reference when present', function () {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $lastUsedAt = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-01-10T00:00:00+00:00');

        $token = AuthToken::reconstitute(
            id: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            tokenKind: TokenKind::Session,
            expiresAt: $expiresAt,
            lastUsedAt: $lastUsedAt,
            createdAt: $createdAt,
        );

        expect($token->isIdleExpiredAt(Carbon::parse('2026-01-02T12:00:00+00:00')))->toBeFalse()
            ->and($token->isIdleExpiredAt(Carbon::parse('2026-01-02T12:00:01+00:00')))->toBeTrue();
    });

    it('applies session idle ttl of 24 hours', function () {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-01-10T00:00:00+00:00');

        $token = AuthToken::issue(
            id: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            tokenKind: TokenKind::Session,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );

        expect($token->isIdleExpiredAt(Carbon::parse('2026-01-02T00:00:00+00:00')))->toBeFalse()
            ->and($token->isIdleExpiredAt(Carbon::parse('2026-01-02T00:00:01+00:00')))->toBeTrue();
    });
});
