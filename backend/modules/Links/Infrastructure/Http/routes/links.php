<?php

declare(strict_types=1);

use Modules\Links\Infrastructure\Http\Controllers\CreateLinkController;

Route::post('/', CreateLinkController::class)
    ->middleware(['auth.bearer', 'token.kind:session', 'throttle.links.create']);
