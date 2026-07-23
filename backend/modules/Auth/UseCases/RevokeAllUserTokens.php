<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Domain\ValueObjects\UserId;

final class RevokeAllUserTokens
{
    public function __construct(
        private readonly AuthTokenRepository $authTokenRepository,
    ) {}

    public function execute(UserId $userId): int
    {
        return $this->authTokenRepository->deleteAllForUser($userId);
    }
}
