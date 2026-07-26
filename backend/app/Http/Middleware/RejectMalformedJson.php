<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * API write requests must declare a JSON Content-Type.
 * Laravel's Request::json() silently coerces invalid JSON to an empty bag;
 * clients that send application/json must get 400 MALFORMED_REQUEST instead.
 */
final class RejectMalformedJson
{
    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requiresJsonContentType($request) && ! $request->isJson()) {
            throw new BadRequestHttpException('The request is malformed.');
        }

        if (! $request->isJson()) {
            return $next($request);
        }

        $content = $request->getContent();

        if (trim($content) === '') {
            return $next($request);
        }

        try {
            json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('The request is malformed.', $exception);
        }

        return $next($request);
    }

    private function requiresJsonContentType(Request $request): bool
    {
        return in_array($request->method(), self::WRITE_METHODS, true);
    }
}
