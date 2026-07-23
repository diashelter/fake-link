<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;

final class TestingAuthProbeController
{
    public function probe(Request $request): JsonResponse
    {
        $principal = $request->attributes->get('authenticated_principal')
            ?? app(AuthenticatedPrincipal::class);

        return response()->json([
            'data' => [
                'user_id' => $principal->userId()->value(),
                'token_kind' => $principal->tokenKind()->value,
            ],
        ]);
    }

    public function sessionOnly(Request $request): JsonResponse
    {
        return $this->probe($request);
    }
}
