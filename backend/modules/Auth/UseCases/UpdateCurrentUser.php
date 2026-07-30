<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\DTOs\Input\UpdateCurrentUserDto;
use Modules\Auth\DTOs\Output\UserProfileDto;
use Modules\Auth\Exceptions\AuthTokenException;

final class UpdateCurrentUser
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function execute(AuthenticatedPrincipal $principal, UpdateCurrentUserDto $input): UserProfileDto
    {
        $profile = $this->userRepository->findProfileById($principal->userId());

        if ($profile === null) {
            throw AuthTokenException::unauthenticated();
        }

        if ($input->name === $profile->user->name()) {
            return $profile;
        }

        $updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->userRepository->update($profile->user->withName($input->name), $updatedAt);

        $reloaded = $this->userRepository->findProfileById($principal->userId());

        if ($reloaded === null) {
            throw AuthTokenException::unauthenticated();
        }

        return $reloaded;
    }
}
