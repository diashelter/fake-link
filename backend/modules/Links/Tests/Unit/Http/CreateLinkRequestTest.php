<?php

declare(strict_types=1);

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\Infrastructure\Http\Requests\CreateLinkRequest;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $payload
 * @return array{status: int, body: array<string, mixed>}|array{status: int, dto: CreateLinkInput}
 */
function validateCreateLink(array $payload): array
{
    $request = CreateLinkRequest::create(
        uri: '/api/v1/links',
        method: 'POST',
        parameters: $payload,
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode($payload, JSON_THROW_ON_ERROR),
    );
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    try {
        $request->validateResolved();

        return [
            'status' => 200,
            'dto' => $request->toDto(),
        ];
    } catch (HttpResponseException $exception) {
        $response = $exception->getResponse();
        expect($response)->toBeInstanceOf(JsonResponse::class);

        /** @var JsonResponse $response */
        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ];
    }
}

/**
 * @param  array{status: int, body?: array<string, mixed>, dto?: CreateLinkInput}  $result
 */
function createLinkErrorCode(array $result, string $field): string
{
    expect($result['status'])->toBe(422)
        ->and($result['body']['errors'])->toHaveKey($field);

    return $result['body']['errors'][$field][0]['code'];
}

describe('CreateLinkRequest destination_url', function () {
    it('requires destination_url', function () {
        $result = validateCreateLink([]);

        expect(createLinkErrorCode($result, 'destination_url'))->toBe('REQUIRED');
    });

    it('rejects an invalid destination with INVALID_DESTINATION_URL', function () {
        $result = validateCreateLink(['destination_url' => 'not-a-url']);

        expect(createLinkErrorCode($result, 'destination_url'))->toBe('INVALID_DESTINATION_URL');
    });

    it('never echoes the destination URL in the error message', function () {
        $marker = 'https://leak-me.example/secret-path?token=abc';
        $result = validateCreateLink(['destination_url' => $marker]);

        $json = json_encode($result['body']);

        expect($json)->not->toContain($marker)
            ->and($json)->not->toContain('leak-me.example')
            ->and($json)->not->toContain('secret-path')
            ->and($json)->not->toContain('token=abc');
    });

    it('accepts a valid destination_url', function () {
        $result = validateCreateLink(['destination_url' => 'https://example.com/ok']);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->destinationUrl)->toBe('https://example.com/ok');
    });

    it('rejects javascript scheme destinations', function () {
        $result = validateCreateLink(['destination_url' => 'javascript:alert(1)']);

        expect(createLinkErrorCode($result, 'destination_url'))->toBe('INVALID_DESTINATION_URL');
    });
});

describe('CreateLinkRequest custom_alias', function () {
    it('rejects explicit null custom_alias as INVALID_ALIAS', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'custom_alias' => null,
        ]);

        expect(createLinkErrorCode($result, 'custom_alias'))->toBe('INVALID_ALIAS');
    });

    it('rejects too-short alias as INVALID_ALIAS', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'custom_alias' => 'ab',
        ]);

        expect(createLinkErrorCode($result, 'custom_alias'))->toBe('INVALID_ALIAS');
    });

    it('rejects invalid characters as INVALID_ALIAS without revealing the rule', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'custom_alias' => 'Bad_Alias!',
        ]);

        expect(createLinkErrorCode($result, 'custom_alias'))->toBe('INVALID_ALIAS');
        $message = $result['body']['errors']['custom_alias'][0]['message'];
        expect(strtolower($message))->not->toContain('character')
            ->and(strtolower($message))->not->toContain('hyphen')
            ->and(strtolower($message))->not->toContain('too short');
    });

    it('accepts a valid custom_alias', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'custom_alias' => 'My-Alias',
        ]);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->customAlias)->toBe('My-Alias');
    });

    it('allows omitting custom_alias for automatic slug', function () {
        $result = validateCreateLink(['destination_url' => 'https://example.com/ok']);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->customAlias)->toBeNull();
    });
});

describe('CreateLinkRequest title', function () {
    it('trims title whitespace', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'title' => '  Hello  ',
        ]);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->title)->toBe('Hello');
    });

    it('normalizes empty title string to null', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'title' => '   ',
        ]);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->title)->toBeNull();
    });

    it('accepts a title of exactly 160 characters', function () {
        $title = str_repeat('a', 160);
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'title' => $title,
        ]);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->title)->toBe($title);
    });

    it('rejects a title of 161 characters as TITLE_TOO_LONG', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'title' => str_repeat('a', 161),
        ]);

        expect(createLinkErrorCode($result, 'title'))->toBe('TITLE_TOO_LONG');
    });

    it('counts title length by characters not bytes', function () {
        // 160 multibyte chars should pass; 161 should fail.
        $ok = str_repeat('á', 160);
        $bad = str_repeat('á', 161);

        expect(validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'title' => $ok,
        ])['status'])->toBe(200);

        expect(createLinkErrorCode(validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'title' => $bad,
        ]), 'title'))->toBe('TITLE_TOO_LONG');
    });

    it('accepts explicit null title', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'title' => null,
        ]);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->title)->toBeNull();
    });
});

describe('CreateLinkRequest expires_at', function () {
    it('rejects non-Z ISO datetime as INVALID_DATETIME', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'expires_at' => '2030-01-01T00:00:00+00:00',
        ]);

        expect(createLinkErrorCode($result, 'expires_at'))->toBe('INVALID_DATETIME');
    });

    it('rejects malformed datetime as INVALID_DATETIME', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'expires_at' => 'not-a-date',
        ]);

        expect(createLinkErrorCode($result, 'expires_at'))->toBe('INVALID_DATETIME');
    });

    it('rejects expires_at equal to now as EXPIRES_AT_NOT_IN_FUTURE', function () {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'expires_at' => $now,
        ]);

        expect(createLinkErrorCode($result, 'expires_at'))->toBe('EXPIRES_AT_NOT_IN_FUTURE');
    });

    it('rejects past expires_at as EXPIRES_AT_NOT_IN_FUTURE', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'expires_at' => '2020-01-01T00:00:00Z',
        ]);

        expect(createLinkErrorCode($result, 'expires_at'))->toBe('EXPIRES_AT_NOT_IN_FUTURE');
    });

    it('accepts a future Z datetime', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'expires_at' => '2030-06-15T12:00:00Z',
        ]);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->expiresAt)->not->toBeNull()
            ->and($result['dto']->expiresAt->format('Y-m-d\TH:i:s\Z'))->toBe('2030-06-15T12:00:00Z');
    });

    it('accepts explicit null expires_at', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'expires_at' => null,
        ]);

        expect($result['status'])->toBe(200)
            ->and($result['dto']->expiresAt)->toBeNull();
    });
});

describe('CreateLinkRequest closed payload', function () {
    it('rejects unknown fields with UNKNOWN_FIELD', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'user_id' => 'not-allowed',
        ]);

        expect(createLinkErrorCode($result, 'user_id'))->toBe('UNKNOWN_FIELD');
    });

    it('rejects multiple unknown fields each with UNKNOWN_FIELD', function () {
        $result = validateCreateLink([
            'destination_url' => 'https://example.com/ok',
            'foo' => 1,
            'bar' => 2,
        ]);

        expect(createLinkErrorCode($result, 'foo'))->toBe('UNKNOWN_FIELD');
        expect(createLinkErrorCode($result, 'bar'))->toBe('UNKNOWN_FIELD');
    });

    it('does not leave rows when validation fails (unit surface — no persistence)', function () {
        $result = validateCreateLink(['destination_url' => 'bad']);

        expect($result['status'])->toBe(422)
            ->and($result['body']['code'])->toBe('VALIDATION_FAILED');
    });
});
