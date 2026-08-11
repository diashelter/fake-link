<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support\OpenApi;

/**
 * Canonical Auth error codes and OpenAPI example messages (ABMC-08).
 *
 * Strings match docs/openapi.yaml examples and AuthErrorResponseFactory.
 */
final class AuthOpenApiCatalog
{
    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    public const TOKEN_RESTRICTED = 'TOKEN_RESTRICTED';

    public const REGISTRATION_NOT_ALLOWED = 'REGISTRATION_NOT_ALLOWED';

    public const INVALID_VERIFICATION_TOKEN = 'INVALID_VERIFICATION_TOKEN';

    public const EMAIL_ALREADY_VERIFIED = 'EMAIL_ALREADY_VERIFIED';

    public const PASSWORD_REUSED = 'PASSWORD_REUSED';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const ACCOUNT_SUSPENDED = 'ACCOUNT_SUSPENDED';

    public const ACCOUNT_PENDING_DELETION = 'ACCOUNT_PENDING_DELETION';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    /**
     * @return list<string>
     */
    public static function errorCodes(): array
    {
        return array_keys(self::messages());
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            self::INVALID_CREDENTIALS => 'The provided credentials are invalid.',
            self::TOKEN_RESTRICTED => 'This token cannot perform the requested operation.',
            self::REGISTRATION_NOT_ALLOWED => 'Registration is not available for these details.',
            self::INVALID_VERIFICATION_TOKEN => 'The verification token is invalid or has expired.',
            self::EMAIL_ALREADY_VERIFIED => 'The email address is already verified.',
            self::PASSWORD_REUSED => 'The new password must be different from the current password.',
            self::UNAUTHENTICATED => 'Authentication is required.',
            self::ACCOUNT_SUSPENDED => 'The account is suspended.',
            self::ACCOUNT_PENDING_DELETION => 'The account is pending deletion.',
            self::VALIDATION_FAILED => 'The given data was invalid.',
            self::RATE_LIMIT_EXCEEDED => 'Too many requests.',
        ];
    }

    public static function message(string $code): string
    {
        $messages = self::messages();

        if (! array_key_exists($code, $messages)) {
            throw new \InvalidArgumentException(sprintf(
                'Auth OpenAPI catalog has no message for code "%s".',
                $code,
            ));
        }

        return $messages[$code];
    }
}
