<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\UseCases\ValidateAuthToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateBearer
{
    private const BEARER_PREFIX = 'Bearer ';

    public function __construct(
        private readonly Application $app,
        private readonly ValidateAuthToken $validateAuthToken,
        private readonly AuthErrorResponseFactory $authErrorResponseFactory,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if (! is_string($authorization) || ! str_starts_with($authorization, self::BEARER_PREFIX)) {
            return $this->authErrorResponseFactory->unauthenticated();
        }

        $plainText = trim(substr($authorization, strlen(self::BEARER_PREFIX)));

        if ($plainText === '') {
            return $this->authErrorResponseFactory->unauthenticated();
        }

        try {
            $principal = $this->validateAuthToken->execute($plainText);
        } catch (AuthTokenException $exception) {
            return $this->authErrorResponseFactory->fromAuthTokenException($exception);
        }

        $this->app->instance(AuthenticatedPrincipal::class, $principal);

        return $next($request);
    }
}
