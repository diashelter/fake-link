<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Exceptions\InviteAllowlistUnavailableException;
use Modules\Auth\Infrastructure\Allowlist\JsonFileInviteAllowlist;
use Tests\TestCase;

uses(TestCase::class);

describe('JsonFileInviteAllowlist', function () {
    it('returns true for an exactly allowlisted normalized email', function () {
        $path = tempAllowlistFile(['invited@example.com']);

        $allowlist = new JsonFileInviteAllowlist($path);

        expect($allowlist->isInvited(EmailAddress::fromString('invited@example.com')))->toBeTrue();
    });

    it('returns false when the email is not on the allowlist', function () {
        $path = tempAllowlistFile(['invited@example.com']);

        $allowlist = new JsonFileInviteAllowlist($path);

        expect($allowlist->isInvited(EmailAddress::fromString('stranger@example.com')))->toBeFalse();
    });

    it('matches after trim and case normalization of the query email', function () {
        $path = tempAllowlistFile(['invited@example.com']);

        $allowlist = new JsonFileInviteAllowlist($path);

        expect($allowlist->isInvited(EmailAddress::fromString('  Invited@Example.com  ')))->toBeTrue();
    });

    it('rejects plus-alias variants that are not listed exactly', function () {
        $path = tempAllowlistFile(['invited@example.com']);

        $allowlist = new JsonFileInviteAllowlist($path);

        expect($allowlist->isInvited(EmailAddress::fromString('invited+alias@example.com')))->toBeFalse();
    });

    it('throws InviteAllowlistUnavailableException when the file is missing', function () {
        $path = sys_get_temp_dir().'/missing-invite-allowlist-'.uniqid('', true).'.json';

        expect(fn () => new JsonFileInviteAllowlist($path))
            ->toThrow(InviteAllowlistUnavailableException::class);

        try {
            new JsonFileInviteAllowlist($path);
        } catch (InviteAllowlistUnavailableException $exception) {
            expect($exception->errorCode())->toBe(InviteAllowlistUnavailableException::SERVICE_UNAVAILABLE)
                ->and($exception->getMessage())->not->toContain('@');
        }
    });

    it('throws InviteAllowlistUnavailableException when JSON is invalid', function () {
        $path = sys_get_temp_dir().'/invalid-invite-allowlist-'.uniqid('', true).'.json';
        file_put_contents($path, '{not-json');

        try {
            expect(fn () => new JsonFileInviteAllowlist($path))
                ->toThrow(InviteAllowlistUnavailableException::class);
        } finally {
            @unlink($path);
        }
    });

    it('does not expose consulted emails in production exception messages', function () {
        $email = 'invited@example.com';
        $path = tempAllowlistFile([$email]);
        $allowlist = new JsonFileInviteAllowlist($path);

        expect($allowlist->isInvited(EmailAddress::fromString($email)))->toBeTrue();

        $missingPath = sys_get_temp_dir().'/missing-invite-allowlist-'.uniqid('', true).'.json';
        $caught = null;

        try {
            new JsonFileInviteAllowlist($missingPath);
        } catch (InviteAllowlistUnavailableException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBeInstanceOf(InviteAllowlistUnavailableException::class)
            ->and($caught->getMessage())->not->toContain($email)
            ->and($caught->getMessage())->not->toContain('@');
    });
});

/**
 * @param  list<string>  $emails
 */
function tempAllowlistFile(array $emails): string
{
    $path = sys_get_temp_dir().'/invite-allowlist-'.uniqid('', true).'.json';
    file_put_contents($path, json_encode(['emails' => $emails], JSON_THROW_ON_ERROR));

    return $path;
}
