<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Repositories;

use DateTimeImmutable;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

interface AuthTokenRepository
{
    public function save(AuthToken $token, string $tokenHash): void;

    public function findByHash(string $tokenHash): ?AuthToken;

    public function deleteById(AuthTokenId $id): void;

    public function deleteByHash(string $tokenHash): void;

    public function deleteAllForUser(UserId $userId): int;

    public function touchLastUsedAtIfStale(AuthTokenId $id, DateTimeImmutable $now, int $minIntervalSeconds): bool;
}
