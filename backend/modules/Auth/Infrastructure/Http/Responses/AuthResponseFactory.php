<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;
use Modules\Auth\DTOs\Output\LoggedInUserDto;
use Modules\Auth\DTOs\Output\RegisteredUserDto;
use Modules\Auth\Infrastructure\Http\Resources\AuthUserResource;
use Symfony\Component\HttpFoundation\Response;

final class AuthResponseFactory
{
    public function issued(RegisteredUserDto $registered, ?string $requestId = null): JsonResponse
    {
        $resolvedRequestId = $requestId ?? 'stub-request-id';
        $user = $registered->user;
        $token = $registered->token;

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'token_kind' => $token->tokenKind->value,
                'expires_at' => AuthUserResource::formatUtc($token->expiresAt),
                'user' => AuthUserResource::toArray($user),
            ],
        ], Response::HTTP_CREATED)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => $resolvedRequestId,
        ]);
    }

    public function authenticated(LoggedInUserDto $loggedIn, ?string $requestId = null): JsonResponse
    {
        $resolvedRequestId = $requestId ?? 'stub-request-id';
        $user = $loggedIn->user;
        $token = $loggedIn->token;

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'token_kind' => $token->tokenKind->value,
                'expires_at' => AuthUserResource::formatUtc($token->expiresAt),
                'user' => AuthUserResource::toArray($user),
            ],
        ], Response::HTTP_OK)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => $resolvedRequestId,
        ]);
    }

    public function accepted(?string $requestId = null): Response
    {
        $resolvedRequestId = $requestId ?? 'stub-request-id';

        return response('', Response::HTTP_ACCEPTED)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => $resolvedRequestId,
        ]);
    }

    public function noContent(?string $requestId = null): Response
    {
        $resolvedRequestId = $requestId ?? 'stub-request-id';

        return response('', Response::HTTP_NO_CONTENT)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => $resolvedRequestId,
        ]);
    }
}
