<?php

declare(strict_types=1);

use DateTimeImmutable;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Services\EffectiveStatus;

describe('EffectiveStatus', function () {
    beforeEach(function () {
        $this->status = new EffectiveStatus;
        $this->now = new DateTimeImmutable('2025-01-01T12:00:00Z');
    });

    it('returns active when not blocked, not expired, and enabled', function () {
        expect($this->status->for(
            blockedAt: null,
            expiresAt: null,
            isEnabled: true,
            now: $this->now,
        ))->toBe(LinkStatus::Active);
    });

    it('returns active when expires_at is strictly after now and enabled', function () {
        $future = $this->now->modify('+1 second');

        expect($this->status->for(
            blockedAt: null,
            expiresAt: $future,
            isEnabled: true,
            now: $this->now,
        ))->toBe(LinkStatus::Active);
    });

    it('returns inactive when not blocked, not expired, and disabled', function () {
        expect($this->status->for(
            blockedAt: null,
            expiresAt: null,
            isEnabled: false,
            now: $this->now,
        ))->toBe(LinkStatus::Inactive);
    });

    it('returns expired when expires_at equals now (exclusive upper bound)', function () {
        expect($this->status->for(
            blockedAt: null,
            expiresAt: $this->now,
            isEnabled: true,
            now: $this->now,
        ))->toBe(LinkStatus::Expired);
    });

    it('returns expired when expires_at is in the past', function () {
        $past = $this->now->modify('-1 second');

        expect($this->status->for(
            blockedAt: null,
            expiresAt: $past,
            isEnabled: true,
            now: $this->now,
        ))->toBe(LinkStatus::Expired);
    });

    it('returns blocked when blocked_at is set regardless of enabled and expiry', function () {
        $blockedAt = new DateTimeImmutable('2024-06-01T00:00:00Z');

        expect($this->status->for(
            blockedAt: $blockedAt,
            expiresAt: null,
            isEnabled: true,
            now: $this->now,
        ))->toBe(LinkStatus::Blocked);
    });

    it('gives blocked precedence when blocked, expired, and disabled coincide', function () {
        expect($this->status->for(
            blockedAt: new DateTimeImmutable('2024-06-01T00:00:00Z'),
            expiresAt: $this->now->modify('-1 day'),
            isEnabled: false,
            now: $this->now,
        ))->toBe(LinkStatus::Blocked);
    });

    it('gives expired precedence over inactive when not blocked', function () {
        expect($this->status->for(
            blockedAt: null,
            expiresAt: $this->now,
            isEnabled: false,
            now: $this->now,
        ))->toBe(LinkStatus::Expired);
    });

    it('returns inactive when disabled with a future expires_at and not blocked', function () {
        expect($this->status->for(
            blockedAt: null,
            expiresAt: $this->now->modify('+1 day'),
            isEnabled: false,
            now: $this->now,
        ))->toBe(LinkStatus::Inactive);
    });

    it('treats a null expires_at as never expired', function () {
        expect($this->status->for(
            blockedAt: null,
            expiresAt: null,
            isEnabled: true,
            now: $this->now,
        ))->toBe(LinkStatus::Active)
            ->and($this->status->for(
                blockedAt: null,
                expiresAt: null,
                isEnabled: false,
                now: $this->now,
            ))->toBe(LinkStatus::Inactive);
    });
});
