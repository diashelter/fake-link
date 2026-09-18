<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Exceptions\LinksDomainException;

describe('LinksDomainException', function () {
    it('exposes error code INVALID_DESTINATION_URL', function () {
        $exception = LinksDomainException::invalidDestinationUrl(DestinationRejectionReason::MalformedUrl);

        expect($exception->errorCode())->toBe(LinksDomainException::INVALID_DESTINATION_URL);
    });

    it('carries a fixed message identical for every rejection reason', function () {
        foreach (DestinationRejectionReason::cases() as $reason) {
            $exception = LinksDomainException::invalidDestinationUrl($reason);

            expect($exception->getMessage())->toBe('The destination URL is not allowed.');
        }
    });

    it('reason() returns the reason the exception was constructed with', function () {
        foreach (DestinationRejectionReason::cases() as $reason) {
            $exception = LinksDomainException::invalidDestinationUrl($reason);

            expect($exception->reason())->toBe($reason);
        }
    });

    it('exposes error code INVALID_SHORT_LINK_ID', function () {
        $exception = LinksDomainException::invalidShortLinkId('some-raw');

        expect($exception->errorCode())->toBe(LinksDomainException::INVALID_SHORT_LINK_ID);
    });

    it('exposes error code INVALID_LINK_DESTINATION_VERSION_ID', function () {
        $exception = LinksDomainException::invalidLinkDestinationVersionId('some-raw');

        expect($exception->errorCode())->toBe(LinksDomainException::INVALID_LINK_DESTINATION_VERSION_ID);
    });

    it('does not interpolate raw value in invalidShortLinkId message', function () {
        $raw = 'secret-raw-id-value';
        $exception = LinksDomainException::invalidShortLinkId($raw);

        expect($exception->getMessage())->not->toContain($raw);
    });

    it('does not interpolate raw value in invalidLinkDestinationVersionId message', function () {
        $raw = 'secret-version-id-value';
        $exception = LinksDomainException::invalidLinkDestinationVersionId($raw);

        expect($exception->getMessage())->not->toContain($raw);
    });

    it('is a DomainException', function () {
        $exception = LinksDomainException::invalidDestinationUrl(DestinationRejectionReason::MalformedUrl);

        expect($exception)->toBeInstanceOf(DomainException::class);
    });
});
