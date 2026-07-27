<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Entities\EmailActionToken;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\ValueObjects\EmailActionTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

describe('EmailActionToken', function () {
    it('reports expired when now is at or after expires_at', function () {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-01-01T01:00:00+00:00');

        $token = EmailActionToken::issue(
            id: EmailActionTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            purpose: EmailActionPurpose::EmailVerification,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );

        expect($token->isExpiredAt(Carbon::parse('2026-01-01T00:59:59+00:00')))->toBeFalse()
            ->and($token->isExpiredAt(Carbon::parse('2026-01-01T01:00:00+00:00')))->toBeTrue()
            ->and($token->isExpiredAt(Carbon::parse('2026-01-01T01:00:01+00:00')))->toBeTrue();
    });

    it('reports unused when used_at is null and used when present', function () {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2026-01-01T01:00:00+00:00');

        $unused = EmailActionToken::issue(
            id: EmailActionTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            purpose: EmailActionPurpose::EmailVerification,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );

        $used = EmailActionToken::reconstitute(
            id: EmailActionTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            purpose: EmailActionPurpose::EmailVerification,
            expiresAt: $expiresAt,
            usedAt: new DateTimeImmutable('2026-01-01T00:30:00+00:00'),
            createdAt: $createdAt,
        );

        expect($unused->isUsed())->toBeFalse()
            ->and($unused->usedAt())->toBeNull()
            ->and($used->isUsed())->toBeTrue()
            ->and($used->usedAt()?->format('Y-m-d\TH:i:sP'))->toBe('2026-01-01T00:30:00+00:00');
    });

    it('exposes email verification purpose on issue', function () {
        $token = EmailActionToken::issue(
            id: EmailActionTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            purpose: EmailActionPurpose::EmailVerification,
            expiresAt: new DateTimeImmutable('2026-01-01T01:00:00+00:00'),
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        expect($token->purpose())->toBe(EmailActionPurpose::EmailVerification)
            ->and($token->purpose()->absoluteTtlSeconds())->toBe(3600);
    });
});
