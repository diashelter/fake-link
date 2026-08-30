<?php

declare(strict_types=1);

use DateTimeImmutable;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Services\LinkStatusResolver;

describe('LinkStatusResolver', function () {
    beforeEach(function () {
        $this->resolver = new LinkStatusResolver;
        $this->now = new DateTimeImmutable('2025-01-01T12:00:00Z');
    });

    it('returns active when not blocked, not expired, and enabled', function () {
        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: null,
            isEnabled: true,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Active);
    });

    it('returns active when expires_at is in the future and enabled', function () {
        $future = new DateTimeImmutable('2025-12-31T12:00:00Z');

        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: $future,
            isEnabled: true,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Active);
    });

    it('returns inactive when not blocked, not expired, and disabled', function () {
        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: null,
            isEnabled: false,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Inactive);
    });

    it('returns expired when expires_at equals now (exclusive boundary)', function () {
        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: $this->now,
            isEnabled: true,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Expired);
    });

    it('returns expired when expires_at is in the past', function () {
        $past = new DateTimeImmutable('2024-01-01T12:00:00Z');

        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: $past,
            isEnabled: true,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Expired);
    });

    it('returns blocked when blocked_at is set regardless of other flags', function () {
        $blocked = new DateTimeImmutable('2024-06-01T00:00:00Z');

        $status = $this->resolver->resolve(
            blockedAt: $blocked,
            expiresAt: null,
            isEnabled: true,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Blocked);
    });

    it('returns blocked when blocked, expired and disabled simultaneously', function () {
        $blocked = new DateTimeImmutable('2024-06-01T00:00:00Z');
        $past = new DateTimeImmutable('2024-01-01T12:00:00Z');

        $status = $this->resolver->resolve(
            blockedAt: $blocked,
            expiresAt: $past,
            isEnabled: false,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Blocked);
    });

    it('returns expired when not blocked but expired and disabled', function () {
        $past = new DateTimeImmutable('2024-01-01T12:00:00Z');

        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: $past,
            isEnabled: false,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Expired);
    });

    it('returns inactive when not blocked, not expired, expires_at is future but disabled', function () {
        $future = new DateTimeImmutable('2026-01-01T12:00:00Z');

        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: $future,
            isEnabled: false,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Inactive);
    });

    it('blocked takes precedence over expired (exclusive: blocked + expired => blocked)', function () {
        $blockedAt = new DateTimeImmutable('2024-06-01T00:00:00Z');
        $expiresAt = new DateTimeImmutable('2024-01-01T00:00:00Z'); // past

        $status = $this->resolver->resolve(
            blockedAt: $blockedAt,
            expiresAt: $expiresAt,
            isEnabled: true,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Blocked);
    });

    it('expired takes precedence over inactive', function () {
        $past = new DateTimeImmutable('2024-01-01T12:00:00Z');

        $status = $this->resolver->resolve(
            blockedAt: null,
            expiresAt: $past,
            isEnabled: false,
            now: $this->now,
        );

        expect($status)->toBe(LinkStatus::Expired);
    });
});
