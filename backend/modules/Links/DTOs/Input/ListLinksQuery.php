<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Input;

use InvalidArgumentException;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\DTOs\LinkQueryScope;

final readonly class ListLinksQuery
{
    public const DEFAULT_PER_PAGE = 20;

    public const MIN_PER_PAGE = 1;

    public const MAX_PER_PAGE = 100;

    public const MIN_SEARCH_LENGTH = 2;

    public const MAX_SEARCH_LENGTH = 160;

    private function __construct(
        public int $perPage,
        public ?string $search,
        public ?LinkStatus $status,
    ) {}

    public static function from(
        ?int $perPage = null,
        ?string $search = null,
        ?string $status = null,
    ): self {
        return new self(
            perPage: self::normalizePerPage($perPage),
            search: self::normalizeSearch($search),
            status: self::normalizeStatus($status),
        );
    }

    public function scope(): LinkQueryScope
    {
        return new LinkQueryScope(
            search: $this->search,
            status: $this->status,
        );
    }

    private static function normalizePerPage(?int $perPage): int
    {
        $value = $perPage ?? self::DEFAULT_PER_PAGE;

        if ($value < self::MIN_PER_PAGE || $value > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('per_page must be an integer between 1 and 100.');
        }

        return $value;
    }

    private static function normalizeSearch(?string $search): ?string
    {
        if ($search === null) {
            return null;
        }

        $trimmed = trim($search);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_SEARCH_LENGTH || $length > self::MAX_SEARCH_LENGTH) {
            throw new InvalidArgumentException('search must be between 2 and 160 characters after trimming.');
        }

        return $trimmed;
    }

    private static function normalizeStatus(?string $status): ?LinkStatus
    {
        if ($status === null || $status === 'all') {
            return null;
        }

        $resolved = LinkStatus::tryFrom($status);

        if ($resolved === null) {
            throw new InvalidArgumentException('status is not a supported effective status.');
        }

        return $resolved;
    }
}
