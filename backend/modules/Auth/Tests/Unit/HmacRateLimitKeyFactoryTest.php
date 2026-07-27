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

    it('returns a stable login email-ip digest without raw ip or email', function () {
        $factory = new HmacRateLimitKeyFactory;
        $ip = '203.0.113.50';
        $email = 'user@example.com';
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forLoginEmailIp($ip, $email);
        $second = $factory->forLoginEmailIp($ip, $email);
        $expected = hash_hmac('sha256', 'login:email-ip:'.$ip.':'.$email, $hmacKey);

        expect($first)->toBe($second)
            ->and($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($ip)
            ->and($first)->not->toContain($email)
            ->and($first)->not->toContain('login:email-ip:');
    });

    it('uses distinct digests for login email-ip sentinel and normalized email', function () {
        $factory = new HmacRateLimitKeyFactory;
        $ip = '198.51.100.77';
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $sentinelKey = $factory->forLoginEmailIp($ip, '_invalid_');
        $emailKey = $factory->forLoginEmailIp($ip, 'valid@example.com');

        expect($sentinelKey)->toBe(hash_hmac('sha256', 'login:email-ip:'.$ip.':_invalid_', $hmacKey))
            ->and($emailKey)->toBe(hash_hmac('sha256', 'login:email-ip:'.$ip.':valid@example.com', $hmacKey))
            ->and($sentinelKey)->not->toBe($emailKey)
            ->and($sentinelKey)->not->toContain('_invalid_');
    });

    it('returns a stable login ip digest without raw ip', function () {
        $factory = new HmacRateLimitKeyFactory;
        $ip = '203.0.113.88';
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forLoginIp($ip);
        $second = $factory->forLoginIp($ip);
        $expected = hash_hmac('sha256', 'login:ip:'.$ip, $hmacKey);

        expect($first)->toBe($second)
            ->and($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($ip)
            ->and($first)->not->toBe($factory->forRegistrationIp($ip))
            ->and($first)->not->toBe($factory->forLoginEmailIp($ip, 'user@example.com'));
    });
});
