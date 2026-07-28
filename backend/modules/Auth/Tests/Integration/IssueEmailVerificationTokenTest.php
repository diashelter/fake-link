<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7EmailActionTokenIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentEmailActionTokenRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueEmailVerificationToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeIssueEmailVerificationTokenUseCase(): IssueEmailVerificationToken
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new IssueEmailVerificationToken(
        emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
        emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

describe('IssueEmailVerificationToken', function () {
    it('issues an email verification token with 3600 second absolute expiry', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $user = UserModel::factory()->create();
        $issued = makeIssueEmailVerificationTokenUseCase()->execute(UserId::fromString($user->id));

        $model = EmailActionTokenModel::query()->find($issued->id->value());
        $hasher = new Sha256TokenHasher;

        expect($issued->expiresAt->format('Y-m-d\TH:i:sP'))->toBe('2026-01-01T01:00:00+00:00')
            ->and(EmailActionPurpose::EmailVerification->absoluteTtlSeconds())->toBe(3600)
            ->and($model)->not->toBeNull()
            ->and($model->purpose)->toBe('email_verification')
            ->and($model->used_at)->toBeNull()
            ->and($model->expires_at->toIso8601String())->toBe('2026-01-01T01:00:00+00:00')
            ->and($model->token_hash)->toBe($hasher->hash($issued->plainTextToken))
            ->and($model->token_hash)->not->toBe($issued->plainTextToken);

        Carbon::setTestNow();
    });

    it('persists only the hash and returns plaintext once in the DTO', function () {
        $user = UserModel::factory()->create();
        $issued = makeIssueEmailVerificationTokenUseCase()->execute(UserId::fromString($user->id));
        $hasher = new Sha256TokenHasher;

        $model = EmailActionTokenModel::query()->find($issued->id->value());

        expect($issued->plainTextToken)->not->toBe('')
            ->and($model?->token_hash)->toBe($hasher->hash($issued->plainTextToken))
            ->and($model?->token_hash)->not->toBe($issued->plainTextToken)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(EmailActionTokenModel::query()->where('token_hash', $issued->plainTextToken)->count())->toBe(0);
    });

    it('invalidates previous unused tokens when re-issuing for the same user', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $user = UserModel::factory()->create();
        $useCase = makeIssueEmailVerificationTokenUseCase();
        $userId = UserId::fromString($user->id);

        $first = $useCase->execute($userId);
        $second = $useCase->execute($userId);

        $firstModel = EmailActionTokenModel::query()->find($first->id->value());
        $secondModel = EmailActionTokenModel::query()->find($second->id->value());

        expect($first->id->value())->not->toBe($second->id->value())
            ->and($firstModel?->used_at?->toIso8601String())->toBe('2026-01-01T00:00:00+00:00')
            ->and($secondModel?->used_at)->toBeNull()
            ->and($secondModel?->purpose)->toBe('email_verification')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(EmailActionTokenModel::query()->where('user_id', $user->id)->count())->toBe(2);

        Carbon::setTestNow();
    });

    it('does not invalidate tokens belonging to other users', function () {
        $user = UserModel::factory()->create();
        $other = UserModel::factory()->create();
        $useCase = makeIssueEmailVerificationTokenUseCase();

        $otherIssued = $useCase->execute(UserId::fromString($other->id));
        $useCase->execute(UserId::fromString($user->id));

        expect(EmailActionTokenModel::query()->find($otherIssued->id->value())?->used_at)->toBeNull();
    });
});
