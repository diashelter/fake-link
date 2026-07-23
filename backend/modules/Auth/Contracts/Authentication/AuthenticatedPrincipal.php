<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Authentication;

use DateTimeImmutable;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

interface AuthenticatedPrincipal
{
    public function userId(): UserId;

    public function userStatus(): UserStatus;

    public function tokenKind(): TokenKind;

    public function tokenId(): AuthTokenId;

    public function expiresAt(): DateTimeImmutable;
}
