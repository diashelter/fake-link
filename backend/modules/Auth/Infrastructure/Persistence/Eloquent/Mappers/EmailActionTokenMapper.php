<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Entities\EmailActionToken;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\ValueObjects\EmailActionTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;

final class EmailActionTokenMapper
{
    public function toDomain(EmailActionTokenModel $model): EmailActionToken
    {
        return EmailActionToken::reconstitute(
            id: EmailActionTokenId::fromString($model->id),
            userId: UserId::fromString($model->user_id),
            purpose: EmailActionPurpose::fromString($model->purpose),
            expiresAt: DateTimeImmutable::createFromInterface($model->expires_at),
            usedAt: $model->used_at !== null
                ? DateTimeImmutable::createFromInterface($model->used_at)
                : null,
            createdAt: DateTimeImmutable::createFromInterface($model->created_at),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPersistence(EmailActionToken $token, string $tokenHash): array
    {
        return [
            'id' => $token->id()->value(),
            'user_id' => $token->userId()->value(),
            'token_hash' => $tokenHash,
            'purpose' => $token->purpose()->value,
            'expires_at' => Carbon::instance($token->expiresAt()),
            'used_at' => $token->usedAt() !== null
                ? Carbon::instance($token->usedAt())
                : null,
            'created_at' => Carbon::instance($token->createdAt()),
        ];
    }
}
