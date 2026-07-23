<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Infrastructure\Http\Controllers\TestingAuthProbeController;

Route::get('/v1/health', function () {
    return response()->json(['status' => 'ok']);
});

if (app()->environment('testing')) {
    Route::prefix('v1/_test/auth')->group(function (): void {
        Route::get('/probe', [TestingAuthProbeController::class, 'probe'])
            ->middleware('auth.bearer');
        Route::get('/session-only', [TestingAuthProbeController::class, 'sessionOnly'])
            ->middleware(['auth.bearer', 'token.kind:session']);
        Route::get('/verification-only', [TestingAuthProbeController::class, 'probe'])
            ->middleware(['auth.bearer', 'token.kind:verification']);
        Route::get('/any-kind', [TestingAuthProbeController::class, 'probe'])
            ->middleware(['auth.bearer', 'token.kind:session,verification']);
    });
}
