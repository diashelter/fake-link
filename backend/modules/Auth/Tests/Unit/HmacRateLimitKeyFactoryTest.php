<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
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

    it('returns a stable email verification resend digest without raw user id', function () {
        $factory = new HmacRateLimitKeyFactory;
        $userId = UserId::fromString('01901234-5678-7abc-89ab-cdef01234567');
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forEmailVerificationResend($userId);
        $second = $factory->forEmailVerificationResend($userId);
        $expected = hash_hmac('sha256', 'email-verification:resend:'.$userId->value(), $hmacKey);

        expect($first)->toBe($second)
            ->and($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($userId->value())
            ->and($first)->not->toBe($factory->forEmailVerificationVerify($userId));
    });

    it('returns a stable email verification verify digest without raw user id', function () {
        $factory = new HmacRateLimitKeyFactory;
        $userId = UserId::fromString('01901234-5678-7abc-89ab-cdef01234568');
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forEmailVerificationVerify($userId);
        $expected = hash_hmac('sha256', 'email-verification:verify:'.$userId->value(), $hmacKey);

        expect($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($userId->value());
    });

    it('returns a stable password reset request digest without raw ip or email', function () {
        $factory = new HmacRateLimitKeyFactory;
        $ip = '203.0.113.40';
        $email = 'reset@example.com';
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forPasswordResetRequest($ip, $email);
        $expected = hash_hmac('sha256', 'password-reset:request:'.$ip.':'.$email, $hmacKey);

        expect($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($ip)
            ->and($first)->not->toContain($email);
    });

    it('returns a stable password reset complete digest without raw ip or token digest', function () {
        $factory = new HmacRateLimitKeyFactory;
        $ip = '203.0.113.41';
        $tokenDigest = hash('sha256', 'presented-token');
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forPasswordResetComplete($ip, $tokenDigest);
        $expected = hash_hmac('sha256', 'password-reset:complete:'.$ip.':'.$tokenDigest, $hmacKey);

        expect($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($ip)
            ->and($first)->not->toContain($tokenDigest);
    });

    it('returns a stable private auth write digest without raw user id', function () {
        $factory = new HmacRateLimitKeyFactory;
        $userId = UserId::fromString('01901234-5678-7abc-89ab-cdef01234569');
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forPrivateAuthWrite($userId);
        $expected = hash_hmac('sha256', 'private-auth:write:'.$userId->value(), $hmacKey);

        expect($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($userId->value());
    });

    it('returns a stable private auth read digest without raw token id', function () {
        $factory = new HmacRateLimitKeyFactory;
        $tokenId = AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef0123456a');
        $hmacKey = (string) config('auth.rate_limit_hmac_key');

        $first = $factory->forPrivateAuthRead($tokenId);
        $expected = hash_hmac('sha256', 'private-auth:read:'.$tokenId->value(), $hmacKey);

        expect($first)->toBe($expected)
            ->and($first)->toMatch('/^[a-f0-9]{64}$/')
            ->and($first)->not->toContain($tokenId->value())
            ->and($first)->not->toBe(
                $factory->forPrivateAuthWrite(UserId::fromString('01901234-5678-7abc-89ab-cdef01234569'))
            );
    });
});
