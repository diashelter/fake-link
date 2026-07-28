<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Illuminate\Support\Carbon;
use Modules\Auth\Contracts\Repositories\EmailActionTokenRepository;
use Modules\Auth\Contracts\Services\EmailActionTokenIdGenerator;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Domain\Entities\EmailActionToken;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Output\IssuedEmailActionTokenDto;

final class IssuePasswordResetToken
{
    public function __construct(
        private readonly EmailActionTokenRepository $emailActionTokenRepository,
        private readonly EmailActionTokenIdGenerator $emailActionTokenIdGenerator,
        private readonly BearerTokenGenerator $bearerTokenGenerator,
        private readonly TokenHasher $tokenHasher,
    ) {}

    public function execute(UserId $userId): IssuedEmailActionTokenDto
    {
        $purpose = EmailActionPurpose::PasswordReset;
        $now = Carbon::now()->toDateTimeImmutable();

        $this->emailActionTokenRepository->invalidateUnusedForUser($userId, $purpose, $now);

        $expiresAt = $now->modify(sprintf('+%d seconds', $purpose->absoluteTtlSeconds()));
        $tokenId = $this->emailActionTokenIdGenerator->generate();
        $plainText = $this->bearerTokenGenerator->generatePlainText();

        $token = EmailActionToken::issue(
            id: $tokenId,
            userId: $userId,
            purpose: $purpose,
            expiresAt: $expiresAt,
            createdAt: $now,
        );

        $this->emailActionTokenRepository->save($token, $this->tokenHasher->hash($plainText));

        return new IssuedEmailActionTokenDto(
            id: $tokenId,
            plainTextToken: $plainText,
            expiresAt: $expiresAt,
        );
    }
}
