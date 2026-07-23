<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Illuminate\Support\Carbon;
use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Contracts\Services\AuthTokenIdGenerator;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\DTOs\Output\IssuedAuthTokenDto;

final class IssueAuthToken
{
    public function __construct(
        private readonly AuthTokenRepository $authTokenRepository,
        private readonly AuthTokenIdGenerator $authTokenIdGenerator,
        private readonly BearerTokenGenerator $bearerTokenGenerator,
        private readonly TokenHasher $tokenHasher,
    ) {}

    public function execute(IssueAuthTokenDto $dto): IssuedAuthTokenDto
    {
        $now = Carbon::now()->toDateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $dto->tokenKind->absoluteTtlSeconds()));
        $tokenId = $this->authTokenIdGenerator->generate();
        $plainText = $this->bearerTokenGenerator->generatePlainText();

        $token = AuthToken::issue(
            id: $tokenId,
            userId: $dto->userId,
            tokenKind: $dto->tokenKind,
            expiresAt: $expiresAt,
            createdAt: $now,
        );

        $this->authTokenRepository->save($token, $this->tokenHasher->hash($plainText));

        return new IssuedAuthTokenDto(
            plainTextToken: $plainText,
            tokenKind: $dto->tokenKind,
            expiresAt: $expiresAt,
            userId: $dto->userId,
            tokenId: $tokenId,
        );
    }
}
