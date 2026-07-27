<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Auth\Domain\Entities\User;

final class AuthUserResource
{
    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     email: string,
     *     status: string,
     *     email_verified_at: string|null,
     *     terms_version: string,
     *     terms_accepted_at: string,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public static function toArray(
        User $user,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ): array {
        $created = $createdAt ?? $user->termsAcceptedAt();
        $updated = $updatedAt ?? $created;

        return [
            'id' => $user->id()->value(),
            'name' => $user->name(),
            'email' => $user->email()->value(),
            'status' => $user->status()->value,
            'email_verified_at' => $user->emailVerifiedAt() !== null
                ? self::formatUtc($user->emailVerifiedAt())
                : null,
            'terms_version' => $user->termsVersion(),
            'terms_accepted_at' => self::formatUtc($user->termsAcceptedAt()),
            'created_at' => self::formatUtc($created),
            'updated_at' => self::formatUtc($updated),
        ];
    }

    public static function formatUtc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
