<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use DomainException;
use Modules\Links\Domain\Enums\SlugRejectionReason;

final class SlugPolicyException extends DomainException
{
    private function __construct(
        private readonly SlugRejectionReason $rejectionReason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromReason(SlugRejectionReason $rejectionReason): self
    {
        return new self(
            rejectionReason: $rejectionReason,
            message: sprintf('Slug policy violation: %s.', $rejectionReason->value),
        );
    }

    public function rejectionReason(): SlugRejectionReason
    {
        return $this->rejectionReason;
    }
}
