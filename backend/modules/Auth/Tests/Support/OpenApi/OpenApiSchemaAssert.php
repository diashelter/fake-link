<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support\OpenApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Structural OpenAPI asserts for Auth contract tests (ABMC-06/07 — MVP subset).
 *
 * Supported schema keywords: type, required, additionalProperties:false, enum, pattern, properties.
 * Explicitly out of MVP: oneOf/allOf, numeric ranges, format beyond pattern.
 */
final class OpenApiSchemaAssert
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowedKeys
     */
    public static function assertExactKeys(array $payload, array $allowedKeys, string $path = '$'): void
    {
        $actual = array_keys($payload);
        sort($actual);
        $expected = $allowedKeys;
        sort($expected);

        Assert::assertSame(
            $expected,
            $actual,
            sprintf('Unexpected keys at %s. Expected [%s], got [%s].', $path, implode(', ', $expected), implode(', ', $actual)),
        );
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    public static function assertErrorEnvelope(
        TestResponse $response,
        int $status,
        string $code,
        string $message,
    ): void {
        $response->assertStatus($status);

        $payload = $response->json();

        Assert::assertTrue(is_array($payload), 'Error envelope JSON body must be an object.');
        /** @var array<string, mixed> $payload */
        self::assertExactKeys($payload, ['code', 'message', 'request_id']);
        Assert::assertSame($code, $payload['code']);
        Assert::assertSame($message, $payload['message']);
        Assert::assertIsString($payload['request_id']);
        Assert::assertNotSame('', $payload['request_id']);
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    public static function assertPrivateCacheAndRequestId(TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        Assert::assertStringContainsString('private', $cacheControl);
        Assert::assertStringContainsString('no-store', $cacheControl);

        $requestId = $response->headers->get('X-Request-ID');

        Assert::assertIsString($requestId);
        Assert::assertNotSame('', $requestId);
    }

    /**
     * @param  array<string, mixed>|list<mixed>|scalar|null  $payload
     * @param  array<string, mixed>  $schema
     */
    public static function assertMatchesSchema(mixed $payload, array $schema, string $path = '$'): void
    {
        if (array_key_exists('type', $schema)) {
            self::assertType($payload, $schema['type'], $path);
        }

        if (is_array($payload) && array_is_list($payload) === false) {
            /** @var array<string, mixed> $objectPayload */
            $objectPayload = $payload;

            if (($schema['additionalProperties'] ?? null) === false && isset($schema['properties']) && is_array($schema['properties'])) {
                self::assertExactKeys($objectPayload, array_keys($schema['properties']), $path);
            }

            if (isset($schema['required']) && is_array($schema['required'])) {
                foreach ($schema['required'] as $requiredKey) {
                    Assert::assertArrayHasKey(
                        $requiredKey,
                        $objectPayload,
                        sprintf('Missing required key %s.%s', $path, $requiredKey),
                    );
                }
            }

            if (isset($schema['properties']) && is_array($schema['properties'])) {
                foreach ($schema['properties'] as $key => $propertySchema) {
                    if (! array_key_exists($key, $objectPayload)) {
                        continue;
                    }

                    if (! is_array($propertySchema)) {
                        continue;
                    }

                    self::assertMatchesSchema($objectPayload[$key], $propertySchema, $path.'.'.$key);
                }
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            Assert::assertContains(
                $payload,
                $schema['enum'],
                sprintf('Value at %s is not in enum.', $path),
            );
        }

        if (isset($schema['pattern']) && is_string($schema['pattern']) && is_string($payload)) {
            Assert::assertMatchesRegularExpression(
                '~'.$schema['pattern'].'~',
                $payload,
                sprintf('Value at %s does not match pattern %s.', $path, $schema['pattern']),
            );
        }

        if (array_key_exists('const', $schema)) {
            Assert::assertSame(
                $schema['const'],
                $payload,
                sprintf('Value at %s does not match const.', $path),
            );
        }
    }

    private static function assertType(mixed $payload, mixed $type, string $path): void
    {
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $matches = match ($candidate) {
                'object' => is_array($payload) && array_is_list($payload) === false,
                'array' => is_array($payload) && array_is_list($payload),
                'string' => is_string($payload),
                'integer' => is_int($payload),
                'number' => is_int($payload) || is_float($payload),
                'boolean' => is_bool($payload),
                'null' => $payload === null,
                default => throw new AssertionFailedError(sprintf('Unsupported OpenAPI type "%s" at %s.', $candidate, $path)),
            };

            if ($matches) {
                return;
            }
        }

        throw new AssertionFailedError(sprintf(
            'Value at %s does not match type(s) [%s].',
            $path,
            implode(', ', array_map(static fn ($t) => is_string($t) ? $t : get_debug_type($t), $types)),
        ));
    }
}
