<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\QueueEmailVerification;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Exceptions\EmailAlreadyVerifiedException;
use Modules\Auth\Exceptions\ResourceNotFoundException;

final class ResendEmailVerification
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly QueueEmailVerification $queueEmailVerification,
    ) {}

    public function execute(AuthenticatedPrincipal $principal): void
    {
        $user = $this->userRepository->findById($principal->userId());

        if ($user === null) {
            throw ResourceNotFoundException::notFound();
        }

        if ($user->status() === UserStatus::Active) {
            throw EmailAlreadyVerifiedException::alreadyVerified();
        }

        $this->queueEmailVerification->dispatch($principal->userId());
    }
}
