<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\LinkQueryScope;
use Modules\Links\Exceptions\InvalidCursor;

interface CursorCodec
{
    public function encode(CursorAnchor $anchor, LinkQueryScope $scope): string;

    /**
     * @throws InvalidCursor
     */
    public function decode(string $cursor, LinkQueryScope $expectedScope): CursorAnchor;
}
