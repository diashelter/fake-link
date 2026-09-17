<?php

declare(strict_types=1);

use Modules\Links\Domain\Services\PublicHostClassifier;
use Tests\TestCase;

uses(TestCase::class);

describe('destination self_hosts config and wiring', function () {
    it('derives self_hosts from SHORT_HOST and the host of APP_URL, lowercased and deduplicated, without a port', function () {
        // phpunit.xml pins SHORT_HOST=go.localhost and APP_URL=https://app.localhost for the test env.
        expect(config('links.destination.self_hosts'))->toBe(['go.localhost', 'app.localhost']);
    });

    it('resolves PublicHostClassifier from the container wired with the config self_hosts list, not an empty default', function () {
        $classifier = app(PublicHostClassifier::class);

        $injectedSelfHosts = (new ReflectionProperty(PublicHostClassifier::class, 'selfHosts'))
            ->getValue($classifier);

        expect($injectedSelfHosts)->toBe(config('links.destination.self_hosts'));
    });

    it('asserts self_hosts is not empty in the test environment', function () {
        expect(config('links.destination.self_hosts'))->not->toBeEmpty();
    });
});
