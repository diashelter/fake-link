<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Input;

use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;

final readonly class IssueAuthTokenDto
{
    public function __construct(
        public UserId $userId,
        public TokenKind $tokenKind,
    ) {}
}
