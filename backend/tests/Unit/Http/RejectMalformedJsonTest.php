<?php

declare(strict_types=1);

use App\Http\Middleware\RejectMalformedJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

uses(TestCase::class);

describe('RejectMalformedJson', function () {
    it('rejects POST without JSON Content-Type', function () {
        $middleware = new RejectMalformedJson;
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true]);
        $request = Request::create('/api/v1/auth/register', 'POST', content: '{"name":"x"}');

        expect(fn () => $middleware->handle($request, $next))
            ->toThrow(BadRequestHttpException::class);
    });

    it('rejects POST with non-JSON Content-Type', function () {
        $middleware = new RejectMalformedJson;
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true]);
        $request = Request::create(
            '/api/v1/auth/register',
            'POST',
            server: ['CONTENT_TYPE' => 'text/plain'],
            content: '{"name":"x"}',
        );

        expect(fn () => $middleware->handle($request, $next))
            ->toThrow(BadRequestHttpException::class);
    });

    it('allows GET without JSON Content-Type', function () {
        $middleware = new RejectMalformedJson;
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true]);
        $request = Request::create('/api/v1/_test/auth/probe', 'GET');

        $response = $middleware->handle($request, $next);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('{"ok":true}');
    });

    it('allows POST with application/json Content-Type', function () {
        $middleware = new RejectMalformedJson;
        $next = static fn (Request $request): JsonResponse => new JsonResponse(['ok' => true]);
        $request = Request::create(
            '/api/v1/auth/register',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"name":"x"}',
        );

        $response = $middleware->handle($request, $next);

        expect($response->getStatusCode())->toBe(200);
    });
});
