<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Output;

use DateTimeImmutable;
use Modules\Auth\Domain\ValueObjects\EmailActionTokenId;

final readonly class IssuedEmailActionTokenDto
{
    public function __construct(
        public EmailActionTokenId $id,
        public string $plainTextToken,
        public DateTimeImmutable $expiresAt,
    ) {}
}
