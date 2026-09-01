<?php

declare(strict_types=1);

use Modules\Links\Exceptions\LinksDomainException;

describe('LinksDomainException', function () {
    it('exposes error code INVALID_DESTINATION_URL', function () {
        $exception = LinksDomainException::invalidDestinationUrl();

        expect($exception->errorCode())->toBe(LinksDomainException::INVALID_DESTINATION_URL);
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
        $exception = LinksDomainException::invalidDestinationUrl();

        expect($exception)->toBeInstanceOf(DomainException::class);
    });
});
