<?php

declare(strict_types=1);

use Modules\Auth\Domain\Enums\UserStatus;

describe('UserStatus', function () {
    it('exposes the four canonical snake_case values', function () {
        expect(UserStatus::cases())->toHaveCount(4)
            ->and(UserStatus::PendingVerification->value)->toBe('pending_verification')
            ->and(UserStatus::Active->value)->toBe('active')
            ->and(UserStatus::Suspended->value)->toBe('suspended')
            ->and(UserStatus::DeletionPending->value)->toBe('deletion_pending');
    });

    it('rejects invalid status strings', function () {
        UserStatus::fromString('invalid_status');
    })->throws(ValueError::class);
});
