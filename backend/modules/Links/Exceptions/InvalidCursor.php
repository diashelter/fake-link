<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use RuntimeException;

final class InvalidCursor extends RuntimeException
{
    public const ERROR_CODE = 'INVALID_CURSOR';

    private function __construct(
        private readonly InvalidCursorReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function malformed(): self
    {
        return new self(InvalidCursorReason::Malformed, 'The cursor is malformed.');
    }

    public static function invalidSignature(): self
    {
        return new self(InvalidCursorReason::InvalidSignature, 'The cursor signature is invalid.');
    }

    public static function invalidTypes(): self
    {
        return new self(InvalidCursorReason::InvalidTypes, 'The cursor payload types are invalid.');
    }

    public static function unsupportedVersion(): self
    {
        return new self(InvalidCursorReason::UnsupportedVersion, 'The cursor version is not supported.');
    }

    public static function scopeMismatch(): self
    {
        return new self(InvalidCursorReason::ScopeMismatch, 'The cursor does not match the current query scope.');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function reason(): InvalidCursorReason
    {
        return $this->reason;
    }
}
