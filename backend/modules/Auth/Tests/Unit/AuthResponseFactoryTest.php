<?php

declare(strict_types=1);

use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Output\IssuedAuthTokenDto;
use Modules\Auth\DTOs\Output\RegisteredUserDto;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Tests\TestCase;

uses(TestCase::class);

function makeRegisteredUserDto(): RegisteredUserDto
{
    $userId = UserId::fromString('01901234-5678-7abc-89ab-cdef01234567');
    $termsAcceptedAt = new DateTimeImmutable('2026-07-26T12:00:00+00:00');

    $user = User::create(
        id: $userId,
        name: 'Invited User',
        email: EmailAddress::fromString('invited@example.com'),
        passwordHash: '$argon2id$v=19$m=65536,t=4,p=1$fixture',
        status: UserStatus::PendingVerification,
        emailVerifiedAt: null,
        termsVersion: '2026-01',
        termsAcceptedAt: $termsAcceptedAt,
    );

    $token = new IssuedAuthTokenDto(
        plainTextToken: 'plain-token-once',
        tokenKind: TokenKind::Verification,
        expiresAt: new DateTimeImmutable('2026-07-27T12:00:00+00:00'),
        userId: $userId,
        tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef01234568'),
    );

    return new RegisteredUserDto($user, $token);
}

describe('AuthResponseFactory', function () {
    it('builds 201 AuthIssued response without success wrapper', function () {
        $response = (new AuthResponseFactory)->issued(makeRegisteredUserDto(), 'req-201');

        expect($response->getStatusCode())->toBe(201)
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->toBe('req-201');

        $payload = $response->getData(true);

        expect($payload)->toHaveKey('data')
            ->and($payload)->not->toHaveKey('success')
            ->and($payload)->not->toHaveKey('message')
            ->and($payload['data']['token'])->toBe('plain-token-once')
            ->and($payload['data']['token_type'])->toBe('Bearer')
            ->and($payload['data']['token_kind'])->toBe('verification')
            ->and($payload['data']['expires_at'])->toBe('2026-07-27T12:00:00Z')
            ->and($payload['data']['user'])->toBe([
                'id' => '01901234-5678-7abc-89ab-cdef01234567',
                'name' => 'Invited User',
                'email' => 'invited@example.com',
                'status' => 'pending_verification',
                'email_verified_at' => null,
                'terms_version' => '2026-01',
                'terms_accepted_at' => '2026-07-26T12:00:00Z',
                'created_at' => '2026-07-26T12:00:00Z',
                'updated_at' => '2026-07-26T12:00:00Z',
            ]);
    });
});
