<?php

declare(strict_types=1);

namespace Modules\Links\Tests\Support\OpenApi;

/**
 * Canonical Links create-link error codes and OpenAPI example messages.
 *
 * Strings match docs/openapi.yaml examples and LinkErrorResponseFactory.
 */
final class LinksOpenApiCatalog
{
    public const ALIAS_UNAVAILABLE = 'ALIAS_UNAVAILABLE';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    public const SLUG_GENERATION_FAILED = 'SLUG_GENERATION_FAILED';

    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    public const INVALID_IDEMPOTENCY_KEY = 'INVALID_IDEMPOTENCY_KEY';

    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            self::ALIAS_UNAVAILABLE => 'The requested alias is unavailable.',
            self::VALIDATION_FAILED => 'The given data was invalid.',
            self::RATE_LIMIT_EXCEEDED => 'Too many requests.',
            self::SLUG_GENERATION_FAILED => 'Automatic slug generation failed. Please try again.',
            self::IDEMPOTENCY_KEY_REUSED => 'The idempotency key was used with a different request.',
            self::RESOURCE_NOT_FOUND => 'The requested resource was not found.',
            self::SERVICE_UNAVAILABLE => 'The service is temporarily unavailable.',
        ];
    }

    public static function message(string $code): string
    {
        $messages = self::messages();

        if (! array_key_exists($code, $messages)) {
            throw new \InvalidArgumentException(sprintf(
                'Links OpenAPI catalog has no message for code "%s".',
                $code,
            ));
        }

        return $messages[$code];
    }
}
