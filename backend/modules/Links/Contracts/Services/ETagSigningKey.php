<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

/**
 * Application HMAC key used to seal strong ETag values.
 * Resolved outside Domain (config/adapter) so Domain never calls config().
 */
interface ETagSigningKey
{
    public function value(): string;
}
