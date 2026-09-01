<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Enums;

enum SlugSource: string
{
    case Automatic = 'automatic';
    case Custom = 'custom';
}
