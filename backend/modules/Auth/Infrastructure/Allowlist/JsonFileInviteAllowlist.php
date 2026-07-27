<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Allowlist;

use JsonException;
use Modules\Auth\Contracts\Services\InviteAllowlist;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Exceptions\InviteAllowlistUnavailableException;
use Throwable;

final class JsonFileInviteAllowlist implements InviteAllowlist
{
    /** @var array<string, true> */
    private readonly array $emails;

    public function __construct(?string $path = null)
    {
        $resolvedPath = $path ?? (string) config('auth.invite_allowlist.path');
        $this->emails = $this->load($resolvedPath);
    }

    public function isInvited(EmailAddress $email): bool
    {
        return isset($this->emails[$email->value()]);
    }

    /**
     * @return array<string, true>
     */
    private function load(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw InviteAllowlistUnavailableException::unavailable();
        }

        try {
            $raw = file_get_contents($path);

            if ($raw === false) {
                throw InviteAllowlistUnavailableException::unavailable();
            }

            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException|InviteAllowlistUnavailableException $exception) {
            if ($exception instanceof InviteAllowlistUnavailableException) {
                throw $exception;
            }

            throw InviteAllowlistUnavailableException::unavailable();
        } catch (Throwable) {
            throw InviteAllowlistUnavailableException::unavailable();
        }

        if (! is_array($decoded) || ! array_key_exists('emails', $decoded) || ! is_array($decoded['emails'])) {
            throw InviteAllowlistUnavailableException::unavailable();
        }

        $emails = [];

        foreach ($decoded['emails'] as $entry) {
            if (! is_string($entry)) {
                throw InviteAllowlistUnavailableException::unavailable();
            }

            try {
                $normalized = EmailAddress::fromString($entry)->value();
            } catch (Throwable) {
                throw InviteAllowlistUnavailableException::unavailable();
            }

            if ($entry !== $normalized) {
                throw InviteAllowlistUnavailableException::unavailable();
            }

            $emails[$normalized] = true;
        }

        return $emails;
    }
}
