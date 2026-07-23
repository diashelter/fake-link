<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Exceptions\AuthDomainException;

describe('EmailAddress', function () {
    it('normalizes trim and lowercase', function () {
        $email = EmailAddress::fromString('  A@B.C  ');

        expect($email->value())->toBe('a@b.c');
    });

    it('rejects invalid email syntax', function () {
        EmailAddress::fromString('not-an-email');
    })->throws(AuthDomainException::class, 'The provided email address is invalid.');

    it('collapses case-only differences for equality', function () {
        $first = EmailAddress::fromString('User@Example.com');
        $second = EmailAddress::fromString('user@example.com');

        expect($first->equals($second))->toBeTrue();
    });

    it('rejects empty string after trim', function () {
        EmailAddress::fromString('   ');
    })->throws(AuthDomainException::class);

    it('does not expose raw input in exception messages', function () {
        try {
            EmailAddress::fromString('secret@bad');
        } catch (AuthDomainException $exception) {
            expect($exception->getMessage())->not->toContain('secret@bad');

            return;
        }

        throw new RuntimeException('Expected AuthDomainException was not thrown.');
    });
});
