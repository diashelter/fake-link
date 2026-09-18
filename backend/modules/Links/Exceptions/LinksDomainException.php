<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use DomainException;
use Modules\Links\Domain\Enums\DestinationRejectionReason;

final class LinksDomainException extends DomainException
{
    public const INVALID_DESTINATION_URL = 'INVALID_DESTINATION_URL';

    public const INVALID_SHORT_LINK_ID = 'INVALID_SHORT_LINK_ID';

    public const INVALID_LINK_DESTINATION_VERSION_ID = 'INVALID_LINK_DESTINATION_VERSION_ID';

    public const INVALID_IDEMPOTENCY_KEY = 'INVALID_IDEMPOTENCY_KEY';

    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly ?DestinationRejectionReason $reason = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidDestinationUrl(DestinationRejectionReason $reason): self
    {
        return new self(
            errorCode: self::INVALID_DESTINATION_URL,
            message: 'The destination URL is not allowed.',
            reason: $reason,
        );
    }

    public static function invalidShortLinkId(string $raw): self
    {
        return new self(
            errorCode: self::INVALID_SHORT_LINK_ID,
            message: 'The provided short link identifier is invalid.',
        );
    }

    public static function invalidLinkDestinationVersionId(string $raw): self
    {
        return new self(
            errorCode: self::INVALID_LINK_DESTINATION_VERSION_ID,
            message: 'The provided link destination version identifier is invalid.',
        );
    }

    public static function invalidIdempotencyKey(): self
    {
        return new self(
            errorCode: self::INVALID_IDEMPOTENCY_KEY,
            message: 'The Idempotency-Key header is invalid.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function reason(): ?DestinationRejectionReason
    {
        return $this->reason;
    }
}
