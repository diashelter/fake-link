<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeIssueAuthTokenUseCase(): IssueAuthToken
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new IssueAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

describe('IssueAuthToken', function () {
    it('issues verification tokens with 24 hour absolute expiry', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $user = UserModel::factory()->create();
        $issued = makeIssueAuthTokenUseCase()->execute(
            new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
        );

        $model = AuthTokenModel::query()->find($issued->tokenId->value());
        $hasher = new Sha256TokenHasher;

        expect($issued->tokenKind)->toBe(TokenKind::Verification)
            ->and($issued->expiresAt->format('Y-m-d\TH:i:sP'))->toBe('2026-01-02T00:00:00+00:00')
            ->and($model?->token_hash)->toBe($hasher->hash($issued->plainTextToken))
            ->and($model?->token_hash)->not->toBe($issued->plainTextToken);

        Carbon::setTestNow();
    });

    it('issues session tokens with 7 day absolute expiry', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $user = UserModel::factory()->create();
        $issued = makeIssueAuthTokenUseCase()->execute(
            new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
        );

        expect($issued->tokenKind)->toBe(TokenKind::Session)
            ->and($issued->expiresAt->format('Y-m-d\TH:i:sP'))->toBe('2026-01-08T00:00:00+00:00');

        Carbon::setTestNow();
    });

    it('persists unique hashes for successive issuances', function () {
        $user = UserModel::factory()->create();
        $useCase = makeIssueAuthTokenUseCase();
        $dto = new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session);

        $first = $useCase->execute($dto);
        $second = $useCase->execute($dto);
        $hasher = new Sha256TokenHasher;

        expect($hasher->hash($first->plainTextToken))->not->toBe($hasher->hash($second->plainTextToken));
        // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder count)
        expect(AuthTokenModel::query()->count())->toBe(2);
    });
});
