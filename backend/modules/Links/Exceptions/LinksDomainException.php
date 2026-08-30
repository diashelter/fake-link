<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use DomainException;

final class LinksDomainException extends DomainException
{
    public const INVALID_SLUG = 'INVALID_SLUG';

    public const INVALID_DESTINATION_URL = 'INVALID_DESTINATION_URL';

    public const INVALID_SHORT_LINK_ID = 'INVALID_SHORT_LINK_ID';

    public const INVALID_LINK_DESTINATION_VERSION_ID = 'INVALID_LINK_DESTINATION_VERSION_ID';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidSlug(): self
    {
        return new self(
            errorCode: self::INVALID_SLUG,
            message: 'The provided slug is invalid.',
        );
    }

    public static function invalidDestinationUrl(): self
    {
        return new self(
            errorCode: self::INVALID_DESTINATION_URL,
            message: 'The provided destination URL is invalid.',
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

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
