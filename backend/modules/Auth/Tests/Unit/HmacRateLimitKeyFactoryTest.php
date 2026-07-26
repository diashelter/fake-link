<?php

declare(strict_types=1);

use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Tests\TestCase;

uses(TestCase::class);

describe('HmacRateLimitKeyFactory', function () {
    it('returns a stable hex digest for the same ip and hmac key', function () {
        $factory = new HmacRateLimitKeyFactory;
        $ip = '203.0.113.10';

        $first = $factory->forRegistrationIp($ip);
        $second = $factory->forRegistrationIp($ip);

        expect($first)->toBe($second)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/');
    });

    it('does not include the raw ip in the returned key', function () {
        $ip = '203.0.113.10';
        $key = (new HmacRateLimitKeyFactory)->forRegistrationIp($ip);

        expect($key)->not->toContain($ip)
            ->and($key)->not->toContain('203.0.113');
    });

    it('uses the registration purpose prefix in the hmac message', function () {
        $ip = '198.51.100.20';
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $expected = hash_hmac('sha256', 'registration:'.$ip, $hmacKey);
        $actual = (new HmacRateLimitKeyFactory)->forRegistrationIp($ip);

        expect($actual)->toBe($expected)
            ->and($actual)->not->toBe(hash_hmac('sha256', 'login:'.$ip, $hmacKey));
    });
});
