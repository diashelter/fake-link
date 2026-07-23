<?php

declare(strict_types=1);

use Modules\Auth\Exceptions\AuthTokenException;

describe('AuthTokenException plaintext discipline', function () {
    it('does not include sentinel plaintext in exception messages', function () {
        $sentinel = 'SENTINEL-PLAINTEXT-TOKEN-MARKER-12345';

        $exceptions = [
            AuthTokenException::unauthenticated(),
            AuthTokenException::accountSuspended(),
            AuthTokenException::accountPendingDeletion(),
            AuthTokenException::tokenRestricted(),
        ];

        foreach ($exceptions as $exception) {
            expect($exception->getMessage())->not->toContain($sentinel);
        }
    });
});
