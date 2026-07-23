<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;

final class RevokeAuthToken
{
    public function __construct(
        private readonly AuthTokenRepository $authTokenRepository,
    ) {}

    public function byId(AuthTokenId $id): void
    {
        $this->authTokenRepository->deleteById($id);
    }

    public function byHash(string $tokenHash): void
    {
        $this->authTokenRepository->deleteByHash($tokenHash);
    }
}
