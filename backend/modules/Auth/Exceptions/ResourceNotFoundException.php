<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class ResourceNotFoundException extends DomainException
{
    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(
            errorCode: self::RESOURCE_NOT_FOUND,
            message: 'The requested resource was not found.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
