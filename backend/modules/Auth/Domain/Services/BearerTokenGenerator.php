<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Services;

final class BearerTokenGenerator
{
    public function generatePlainText(): string
    {
        $bytes = random_bytes(32);

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
