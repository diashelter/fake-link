<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Domain\Entities\EmailActionToken;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\ValueObjects\EmailActionTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentEmailActionTokenRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeEmailActionTokenRepository(): EloquentEmailActionTokenRepository
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new EloquentEmailActionTokenRepository(
        emailActionTokenMapper: new EmailActionTokenMapper,
    );
}

function makePersistableEmailActionToken(
    UserId $userId,
    ?EmailActionTokenId $id = null,
    ?DateTimeImmutable $expiresAt = null,
    ?DateTimeImmutable $createdAt = null,
): EmailActionToken {
    $created = $createdAt ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    $expires = $expiresAt ?? $created->modify('+3600 seconds');

    return EmailActionToken::issue(
        id: $id ?? EmailActionTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
        userId: $userId,
        purpose: EmailActionPurpose::EmailVerification,
        expiresAt: $expires,
        createdAt: $created,
    );
}

describe('EloquentEmailActionTokenRepository', function () {
    it('persists only the token hash and never plaintext', function () {
        $user = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $plainText = 'test-email-verification-plaintext';
        $token = makePersistableEmailActionToken(UserId::fromString($user->id));

        $repository->save($token, $hasher->hash($plainText));

        $model = EmailActionTokenModel::query()->find($token->id()->value());

        expect($model)->not->toBeNull()
            ->and($model->token_hash)->toBe($hasher->hash($plainText))
            ->and($model->token_hash)->not->toBe($plainText)
            ->and($model->purpose)->toBe('email_verification')
            ->and($model->used_at)->toBeNull();
    });

    it('finds a token by hash and returns null when missing', function () {
        $user = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $plainText = 'lookup-email-token';
        $hash = $hasher->hash($plainText);
        $token = makePersistableEmailActionToken(UserId::fromString($user->id));

        $repository->save($token, $hash);

        $found = $repository->findByHash($hash);

        expect($found)->not->toBeNull()
            ->and($found->id()->value())->toBe($token->id()->value())
            ->and($found->purpose())->toBe(EmailActionPurpose::EmailVerification)
            ->and($repository->findByHash($hasher->hash('missing-email-token')))->toBeNull();
    });

    it('invalidates unused tokens for the user and purpose by setting used_at', function () {
        Carbon::setTestNow('2026-01-01T12:00:00+00:00');

        $user = UserModel::factory()->create();
        $otherUser = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;

        $repository->save(
            makePersistableEmailActionToken(UserId::fromString($user->id)),
            $hasher->hash('unused-one'),
        );
        $repository->save(
            makePersistableEmailActionToken(
                UserId::fromString($user->id),
                id: EmailActionTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
            ),
            $hasher->hash('unused-two'),
        );
        $repository->save(
            makePersistableEmailActionToken(
                UserId::fromString($otherUser->id),
                id: EmailActionTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abe'),
            ),
            $hasher->hash('other-user-token'),
        );

        $invalidated = $repository->invalidateUnusedForUser(
            UserId::fromString($user->id),
            EmailActionPurpose::EmailVerification,
            new DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        );

        expect($invalidated)->toBe(2)
            ->and(EmailActionTokenModel::query()->find('018e8b8a-7b6a-7000-8000-123456789abc')?->used_at?->toIso8601String())
            ->toBe('2026-01-01T12:00:00+00:00')
            ->and(EmailActionTokenModel::query()->find('018e8b8a-7b6a-7000-8000-123456789abd')?->used_at?->toIso8601String())
            ->toBe('2026-01-01T12:00:00+00:00')
            ->and(EmailActionTokenModel::query()->find('018e8b8a-7b6a-7000-8000-123456789abe')?->used_at)
            ->toBeNull();

        Carbon::setTestNow();
    });

    it('does not change already used tokens when invalidating unused', function () {
        Carbon::setTestNow('2026-01-01T12:00:00+00:00');

        $user = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $token = makePersistableEmailActionToken(UserId::fromString($user->id));
        $repository->save($token, $hasher->hash('already-used'));

        EmailActionTokenModel::query()->where('id', $token->id()->value())->update([
            'used_at' => Carbon::parse('2026-01-01T10:00:00+00:00'),
        ]);

        $invalidated = $repository->invalidateUnusedForUser(
            UserId::fromString($user->id),
            EmailActionPurpose::EmailVerification,
            new DateTimeImmutable('2026-01-01T12:00:00+00:00'),
        );

        expect($invalidated)->toBe(0)
            ->and(EmailActionTokenModel::query()->find($token->id()->value())?->used_at?->toIso8601String())
            ->toBe('2026-01-01T10:00:00+00:00');

        Carbon::setTestNow();
    });

    it('consumes a valid unused unexpired token for the owning user', function () {
        Carbon::setTestNow('2026-01-01T00:30:00+00:00');

        $user = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash('consumable-token');
        $token = makePersistableEmailActionToken(UserId::fromString($user->id));
        $repository->save($token, $hash);

        $consumed = $repository->consumeForUser(
            $hash,
            UserId::fromString($user->id),
            EmailActionPurpose::EmailVerification,
            new DateTimeImmutable('2026-01-01T00:30:00+00:00'),
        );

        expect($consumed)->toBeTrue()
            ->and(EmailActionTokenModel::query()->find($token->id()->value())?->used_at?->toIso8601String())
            ->toBe('2026-01-01T00:30:00+00:00');

        Carbon::setTestNow();
    });

    it('rejects consume when token is expired', function () {
        $user = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash('expired-token');
        $token = makePersistableEmailActionToken(
            UserId::fromString($user->id),
            expiresAt: new DateTimeImmutable('2026-01-01T01:00:00+00:00'),
        );
        $repository->save($token, $hash);

        $consumed = $repository->consumeForUser(
            $hash,
            UserId::fromString($user->id),
            EmailActionPurpose::EmailVerification,
            new DateTimeImmutable('2026-01-01T01:00:00+00:00'),
        );

        expect($consumed)->toBeFalse()
            ->and(EmailActionTokenModel::query()->find($token->id()->value())?->used_at)
            ->toBeNull();
    });

    it('rejects consume when token was already used', function () {
        Carbon::setTestNow('2026-01-01T00:30:00+00:00');

        $user = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash('used-token');
        $token = makePersistableEmailActionToken(UserId::fromString($user->id));
        $repository->save($token, $hash);

        expect($repository->consumeForUser(
            $hash,
            UserId::fromString($user->id),
            EmailActionPurpose::EmailVerification,
            new DateTimeImmutable('2026-01-01T00:30:00+00:00'),
        ))->toBeTrue();

        expect($repository->consumeForUser(
            $hash,
            UserId::fromString($user->id),
            EmailActionPurpose::EmailVerification,
            new DateTimeImmutable('2026-01-01T00:31:00+00:00'),
        ))->toBeFalse();

        Carbon::setTestNow();
    });

    it('rejects consume when token belongs to another user', function () {
        $owner = UserModel::factory()->create();
        $other = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash('cross-user-token');
        $token = makePersistableEmailActionToken(UserId::fromString($owner->id));
        $repository->save($token, $hash);

        $consumed = $repository->consumeForUser(
            $hash,
            UserId::fromString($other->id),
            EmailActionPurpose::EmailVerification,
            new DateTimeImmutable('2026-01-01T00:30:00+00:00'),
        );

        expect($consumed)->toBeFalse()
            ->and(EmailActionTokenModel::query()->find($token->id()->value())?->used_at)
            ->toBeNull();
    });

    it('allows only one concurrent consume for the same token', function () {
        $user = UserModel::factory()->create();
        $repository = makeEmailActionTokenRepository();
        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash('concurrent-token');
        $token = makePersistableEmailActionToken(UserId::fromString($user->id));
        $repository->save($token, $hash);

        $now = new DateTimeImmutable('2026-01-01T00:30:00+00:00');
        $userId = UserId::fromString($user->id);
        $results = [];

        DB::transaction(function () use ($repository, $hash, $userId, $now, &$results): void {
            $results[] = $repository->consumeForUser(
                $hash,
                $userId,
                EmailActionPurpose::EmailVerification,
                $now,
            );
            $results[] = $repository->consumeForUser(
                $hash,
                $userId,
                EmailActionPurpose::EmailVerification,
                $now,
            );
        });

        expect($results)->toBe([true, false])
            ->and(EmailActionTokenModel::query()->find($token->id()->value())?->used_at)->not->toBeNull();
    });
});
