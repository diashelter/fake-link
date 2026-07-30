<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\DTOs\Output\UserProfileDto;
use Modules\Auth\Exceptions\AuthTokenException;

final class GetCurrentUser
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function execute(AuthenticatedPrincipal $principal): UserProfileDto
    {
        $profile = $this->userRepository->findProfileById($principal->userId());

        if ($profile === null) {
            throw AuthTokenException::unauthenticated();
        }

        return $profile;
    }
}
