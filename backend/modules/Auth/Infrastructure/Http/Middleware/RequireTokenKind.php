<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Symfony\Component\HttpFoundation\Response;

final class RequireTokenKind
{
    public function __construct(
        private readonly Application $app,
        private readonly AuthErrorResponseFactory $authErrorResponseFactory,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$kinds): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);
        $allowedKinds = array_map(
            static fn (string $kind): TokenKind => TokenKind::fromString($kind),
            $kinds,
        );

        if ($principal->tokenKind() === TokenKind::Session
            && $principal->userStatus() === UserStatus::PendingVerification) {
            return $this->authErrorResponseFactory->tokenRestricted();
        }

        if (! in_array($principal->tokenKind(), $allowedKinds, true)) {
            return $this->authErrorResponseFactory->tokenRestricted();
        }

        return $next($request);
    }
}
