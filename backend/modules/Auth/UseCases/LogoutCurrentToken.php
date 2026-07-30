<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;

final class LogoutCurrentToken
{
    public function __construct(
        private readonly RevokeAuthToken $revokeAuthToken,
    ) {}

    public function execute(AuthenticatedPrincipal $principal): void
    {
        $this->revokeAuthToken->byId($principal->tokenId());
    }
}
