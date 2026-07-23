<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Authorization;

use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\ResourceNotFoundException;

trait AuthorizesOwnedResource
{
    protected function ensureOwnedBy(AuthenticatedPrincipal $principal, UserId $ownerId): void
    {
        if (! $principal->userId()->equals($ownerId)) {
            throw ResourceNotFoundException::notFound();
        }
    }
}
