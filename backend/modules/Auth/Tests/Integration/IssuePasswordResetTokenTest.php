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
use Modules\Auth\UseCases\IssuePasswordResetToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeIssuePasswordResetTokenUseCase(): IssuePasswordResetToken
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new IssuePasswordResetToken(
        emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
        emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

describe('IssuePasswordResetToken', function () {
    it('issues a password reset token with 1800 second absolute expiry', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $user = UserModel::factory()->active()->create();
        $issued = makeIssuePasswordResetTokenUseCase()->execute(UserId::fromString($user->id));

        $model = EmailActionTokenModel::query()->find($issued->id->value());
        $hasher = new Sha256TokenHasher;

        expect($issued->expiresAt->format('Y-m-d\TH:i:sP'))->toBe('2026-01-01T00:30:00+00:00')
            ->and(EmailActionPurpose::PasswordReset->absoluteTtlSeconds())->toBe(1800)
            ->and($model)->not->toBeNull()
            ->and($model->purpose)->toBe('password_reset')
            ->and($model->used_at)->toBeNull()
            ->and($model->expires_at->toIso8601String())->toBe('2026-01-01T00:30:00+00:00')
            ->and($model->token_hash)->toBe($hasher->hash($issued->plainTextToken))
            ->and($model->token_hash)->not->toBe($issued->plainTextToken);

        Carbon::setTestNow();
    });

    it('persists only the hash and returns plaintext once in the DTO', function () {
        $user = UserModel::factory()->active()->create();
        $issued = makeIssuePasswordResetTokenUseCase()->execute(UserId::fromString($user->id));
        $hasher = new Sha256TokenHasher;

        $model = EmailActionTokenModel::query()->find($issued->id->value());

        expect($issued->plainTextToken)->not->toBe('')
            ->and($model?->token_hash)->toBe($hasher->hash($issued->plainTextToken))
            ->and($model?->token_hash)->not->toBe($issued->plainTextToken)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(EmailActionTokenModel::query()->where('token_hash', $issued->plainTextToken)->count())->toBe(0);
    });

    it('invalidates previous unused password reset tokens when re-issuing for the same user', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $user = UserModel::factory()->active()->create();
        $useCase = makeIssuePasswordResetTokenUseCase();
        $userId = UserId::fromString($user->id);

        $first = $useCase->execute($userId);
        $second = $useCase->execute($userId);

        $firstModel = EmailActionTokenModel::query()->find($first->id->value());
        $secondModel = EmailActionTokenModel::query()->find($second->id->value());

        expect($first->id->value())->not->toBe($second->id->value())
            ->and($firstModel?->used_at?->toIso8601String())->toBe('2026-01-01T00:00:00+00:00')
            ->and($secondModel?->used_at)->toBeNull()
            ->and($secondModel?->purpose)->toBe('password_reset')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(EmailActionTokenModel::query()->where('user_id', $user->id)->where('purpose', 'password_reset')->count())->toBe(2);

        Carbon::setTestNow();
    });

    it('does not invalidate password reset tokens belonging to other users', function () {
        $user = UserModel::factory()->active()->create();
        $other = UserModel::factory()->active()->create();
        $useCase = makeIssuePasswordResetTokenUseCase();

        $otherIssued = $useCase->execute(UserId::fromString($other->id));
        $useCase->execute(UserId::fromString($user->id));

        expect(EmailActionTokenModel::query()->find($otherIssued->id->value())?->used_at)->toBeNull();
    });

    it('does not invalidate unused email verification tokens for the same user', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $user = UserModel::factory()->active()->create();
        $userId = UserId::fromString($user->id);
        $hasher = new Sha256TokenHasher;

        EmailActionTokenModel::factory()->create([
            'user_id' => $user->id,
            'purpose' => EmailActionPurpose::EmailVerification->value,
            'token_hash' => $hasher->hash('verification-plain'),
            'used_at' => null,
        ]);

        makeIssuePasswordResetTokenUseCase()->execute($userId);

        expect(
            // @phpstan-ignore staticMethod.dynamicCall
            EmailActionTokenModel::query()->where('user_id', $user->id)->where('purpose', EmailActionPurpose::EmailVerification->value)->where('used_at', null)->count()
        )->toBe(1);

        Carbon::setTestNow();
    });
});
