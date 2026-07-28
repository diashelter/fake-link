<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Repositories;

use DateTimeImmutable;
use Modules\Auth\Domain\Entities\EmailActionToken;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\ValueObjects\UserId;

interface EmailActionTokenRepository
{
    public function save(EmailActionToken $token, string $tokenHash): void;

    public function findByHash(string $tokenHash): ?EmailActionToken;

    public function invalidateUnusedForUser(UserId $userId, EmailActionPurpose $purpose, DateTimeImmutable $now): int;

    /**
     * Atomically marks token used if valid for user; returns false if not consumable.
     */
    public function consumeForUser(string $tokenHash, UserId $userId, EmailActionPurpose $purpose, DateTimeImmutable $now): bool;
}
