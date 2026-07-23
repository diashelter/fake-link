<?php

declare(strict_types=1);

use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\ResourceNotFoundException;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Authorization\AuthorizesOwnedResource;

final class OwnedResourceProbe
{
    use AuthorizesOwnedResource;

    public function check(AuthenticatedPrincipal $principal, UserId $ownerId): void
    {
        $this->ensureOwnedBy($principal, $ownerId);
    }
}

describe('AuthorizesOwnedResource', function () {
    it('allows access when the principal owns the resource', function () {
        $userId = UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');
        $principal = new AuthenticatedPrincipalRecord(
            userId: $userId,
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            expiresAt: new DateTimeImmutable('2026-01-08T00:00:00+00:00'),
        );

        (new OwnedResourceProbe)->check($principal, $userId);

        expect(true)->toBeTrue();
    });

    it('throws resource not found when the owner differs', function () {
        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            expiresAt: new DateTimeImmutable('2026-01-08T00:00:00+00:00'),
        );

        (new OwnedResourceProbe)->check(
            $principal,
            UserId::fromString('018e8b8a-7b6a-7000-8000-999999999999'),
        );
    })->throws(ResourceNotFoundException::class, 'The requested resource was not found.');
});
