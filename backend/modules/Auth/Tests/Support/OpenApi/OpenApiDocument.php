<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support\OpenApi;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads docs/openapi.yaml for Auth contract tests (ABMC-06).
 *
 * Resolves local $ref pointers under #/components/schemas/* and
 * #/components/responses/* only (MVP subset — not a full OpenAPI resolver).
 */
final class OpenApiDocument
{
    private static ?self $cached = null;

    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(
        private readonly array $document,
        private readonly string $path,
    ) {}

    public static function load(?string $path = null): self
    {
        $resolvedPath = $path ?? self::defaultPath();

        if (self::$cached !== null && self::$cached->path === $resolvedPath) {
            return self::$cached;
        }

        if (! is_file($resolvedPath)) {
            throw new RuntimeException(sprintf(
                'OpenAPI specification file not found at "%s". Set OPENAPI_SPEC_PATH or mount docs/ at /var/www/docs.',
                $resolvedPath,
            ));
        }

        /** @var array<string, mixed> $document */
        $document = Yaml::parseFile($resolvedPath);

        self::$cached = new self($document, $resolvedPath);

        return self::$cached;
    }

    /**
     * Clears the process-local cache (test isolation).
     */
    public static function clearCache(): void
    {
        self::$cached = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $name): array
    {
        $schemas = $this->document['components']['schemas'] ?? null;

        if (! is_array($schemas) || ! array_key_exists($name, $schemas)) {
            throw new InvalidArgumentException(sprintf(
                'OpenAPI schema "%s" was not found in components.schemas.',
                $name,
            ));
        }

        /** @var array<string, mixed> $schema */
        $schema = $this->resolveNode($schemas[$name]);

        return $schema;
    }

    /**
     * Returns the JSON schema for a response component's application/json content.
     *
     * @return array<string, mixed>
     */
    public function responseSchema(string $responseComponent): array
    {
        $responses = $this->document['components']['responses'] ?? null;

        if (! is_array($responses) || ! array_key_exists($responseComponent, $responses)) {
            throw new InvalidArgumentException(sprintf(
                'OpenAPI response "%s" was not found in components.responses.',
                $responseComponent,
            ));
        }

        $response = $responses[$responseComponent];

        if (! is_array($response)) {
            throw new InvalidArgumentException(sprintf(
                'OpenAPI response "%s" is not an object.',
                $responseComponent,
            ));
        }

        $schema = $response['content']['application/json']['schema'] ?? null;

        if (! is_array($schema)) {
            throw new InvalidArgumentException(sprintf(
                'OpenAPI response "%s" has no application/json schema.',
                $responseComponent,
            ));
        }

        /** @var array<string, mixed> $resolved */
        $resolved = $this->resolveNode($schema);

        return $resolved;
    }

    private static function defaultPath(): string
    {
        $fromEnv = getenv('OPENAPI_SPEC_PATH');

        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        // Fallback for local clarity when phpunit env is not injected.
        return '/var/www/docs/openapi.yaml';
    }

    private function resolveNode(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            return $this->resolveRef($node['$ref']);
        }

        $resolved = [];

        foreach ($node as $key => $value) {
            $resolved[$key] = $this->resolveNode($value);
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRef(string $ref): array
    {
        if (preg_match('~^#/components/(schemas|responses)/([^/]+)$~', $ref, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported OpenAPI $ref "%s". Only #/components/schemas/* and #/components/responses/* are supported.',
                $ref,
            ));
        }

        $collection = $matches[1];
        $name = $matches[2];
        $target = $this->document['components'][$collection][$name] ?? null;

        if (! is_array($target)) {
            throw new InvalidArgumentException(sprintf(
                'OpenAPI $ref "%s" does not resolve to an object.',
                $ref,
            ));
        }

        /** @var array<string, mixed> $resolved */
        $resolved = $this->resolveNode($target);

        return $resolved;
    }
}
