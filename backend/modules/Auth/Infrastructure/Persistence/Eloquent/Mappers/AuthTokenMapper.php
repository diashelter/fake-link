<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;

final class AuthTokenMapper
{
    public function toDomain(AuthTokenModel $model): AuthToken
    {
        return AuthToken::reconstitute(
            id: AuthTokenId::fromString($model->id),
            userId: UserId::fromString($model->user_id),
            tokenKind: TokenKind::fromString($model->token_kind),
            expiresAt: DateTimeImmutable::createFromInterface($model->expires_at),
            lastUsedAt: $model->last_used_at !== null
                ? DateTimeImmutable::createFromInterface($model->last_used_at)
                : null,
            createdAt: DateTimeImmutable::createFromInterface($model->created_at),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPersistence(AuthToken $token, string $tokenHash): array
    {
        return [
            'id' => $token->id()->value(),
            'user_id' => $token->userId()->value(),
            'token_hash' => $tokenHash,
            'token_kind' => $token->tokenKind()->value,
            'expires_at' => Carbon::instance($token->expiresAt()),
            'last_used_at' => $token->lastUsedAt() !== null
                ? Carbon::instance($token->lastUsedAt())
                : null,
            'created_at' => Carbon::instance($token->createdAt()),
        ];
    }
}
