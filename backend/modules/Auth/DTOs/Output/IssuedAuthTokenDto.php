<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Output;

use DateTimeImmutable;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

final readonly class IssuedAuthTokenDto
{
    public function __construct(
        public string $plainTextToken,
        public TokenKind $tokenKind,
        public DateTimeImmutable $expiresAt,
        public UserId $userId,
        public AuthTokenId $tokenId,
    ) {}
}
