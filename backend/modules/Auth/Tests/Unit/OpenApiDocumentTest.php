<?php

declare(strict_types=1);

use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    OpenApiDocument::clearCache();
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

describe('OpenApiDocument', function () {
    it('loads the OpenAPI document and returns the User schema with required keys', function () {
        $document = OpenApiDocument::load();
        $user = $document->schema('User');

        expect($user)->toBeArray()
            ->and($user['type'] ?? null)->toBe('object')
            ->and($user['additionalProperties'] ?? null)->toBeFalse()
            ->and($user['required'] ?? null)->toBe([
                'id',
                'name',
                'email',
                'status',
                'email_verified_at',
                'terms_version',
                'terms_accepted_at',
                'created_at',
                'updated_at',
            ])
            ->and($user['properties'] ?? null)->toBeArray()
            ->and(array_keys($user['properties']))->toEqualCanonicalizing([
                'id',
                'name',
                'email',
                'status',
                'email_verified_at',
                'terms_version',
                'terms_accepted_at',
                'created_at',
                'updated_at',
            ]);
    });

    it('resolves response component $ref to the application/json schema', function () {
        $document = OpenApiDocument::load();
        $authIssued = $document->responseSchema('AuthIssued');

        expect($authIssued['type'] ?? null)->toBe('object')
            ->and($authIssued['required'] ?? null)->toBe(['data'])
            ->and($authIssued['properties'] ?? null)->toHaveKey('data')
            ->and($authIssued['properties']['data']['type'] ?? null)->toBe('object')
            ->and($authIssued['properties']['data']['required'] ?? null)->toContain('token')
            ->and($authIssued['properties']['data']['required'] ?? null)->toContain('user');
    });

    it('fails clearly when the OpenAPI file path is missing', function () {
        $missing = '/tmp/openapi-does-not-exist-'.uniqid('', true).'.yaml';

        expect(fn () => OpenApiDocument::load($missing))
            ->toThrow(RuntimeException::class, sprintf(
                'OpenAPI specification file not found at "%s"',
                $missing,
            ));
    });
});
