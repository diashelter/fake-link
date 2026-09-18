<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Exceptions\LinksDomainException;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Modules\Links\Tests\Support\DestinationSentinel;
use Modules\Links\UseCases\SealDestinationUrl;
use Tests\TestCase;

uses(TestCase::class);

function destinationLeakSelfHost(): string
{
    return 'go.localhost';
}

function destinationLeakSeal(): SealDestinationUrl
{
    $keyring = DestinationKeyring::fromConfig([
        'keyring' => json_encode(['k1' => base64_encode(str_repeat("\x00", 32))]),
        'active_key_id' => 'k1',
    ]);
    $hosts = new PublicHostClassifier([destinationLeakSelfHost()]);

    return new SealDestinationUrl($hosts, new Aes256GcmDestinationCipher($keyring, $hosts));
}

function destinationRejectionReasonByName(string $name): DestinationRejectionReason
{
    foreach (DestinationRejectionReason::cases() as $case) {
        if ($case->name === $name) {
            return $case;
        }
    }

    throw new RuntimeException("Unknown DestinationRejectionReason case: {$name}");
}

/**
 * Captures Log::listen events. Laravel 13 has no Log::fake(); MessageLogged + Log::listen
 * is the native spy (same technique used by Aes256GcmDestinationCipherTest).
 *
 * @return list<MessageLogged>
 */
function captureDestinationLogs(Closure $run): array
{
    $captured = [];
    Log::listen(function (MessageLogged $event) use (&$captured): void {
        $captured[] = $event;
    });

    $run();

    return $captured;
}

describe('Destination non-leakage gate — rejected paths, all 12 reasons (LDST-24)', function () {
    it('never lets the sentinel token appear in logs, the exception message, trace, previous, or getTrace() args', function (string $reasonName) {
        $token = DestinationSentinel::token();
        $rawByReason = DestinationSentinel::rejectionUrls($token, destinationLeakSelfHost());
        $raw = $rawByReason[$reasonName];
        $expectedReason = destinationRejectionReasonByName($reasonName);

        $seal = destinationLeakSeal();
        $caught = null;

        $logs = captureDestinationLogs(function () use ($seal, $raw, &$caught): void {
            try {
                $seal($raw);
            } catch (LinksDomainException $e) {
                $caught = $e;
            }
        });

        expect($caught)->not->toBeNull()
            ->and($caught->reason())->toBe($expectedReason);

        // Log records.
        foreach ($logs as $event) {
            expect($event->message)->not->toContain($token);
            expect(json_encode($event->context))->not->toContain($token);
        }

        // Exception message and rendered trace string.
        expect($caught->getMessage())->not->toContain($token)
            ->and($caught->getTraceAsString())->not->toContain($token)
            ->and($caught->getPrevious())->toBeNull();

        // The structured trace array itself — not just its string rendering. Without the
        // DestinationUrlPolicy::fail() mitigation (ini_set('zend.exception_ignore_args')),
        // PHP captures the *live* value of every calling frame's arguments at throw time,
        // which would include the raw destination URL (and therefore the token) in full.
        foreach ($caught->getTrace() as $frame) {
            expect(json_encode($frame['args'] ?? []))->not->toContain($token);
        }
    })->with([
        'TooLong', 'NonAsciiInput', 'ControlCharacter', 'InvalidPercentEncoding',
        'MalformedUrl', 'SchemeNotAllowed', 'UserinfoPresent', 'IpLiteral',
        'SpecialUseHost', 'SelfHost', 'InvalidHostname', 'InvalidPort',
    ]);
});

describe('Destination non-leakage gate — accepted and encrypted path (LDST-24)', function () {
    it('never lets the sentinel token appear in logs or in the serialized encrypted envelope', function () {
        $token = DestinationSentinel::token();
        $raw = DestinationSentinel::acceptedUrl($token);
        $seal = destinationLeakSeal();

        $encrypted = null;
        $logs = captureDestinationLogs(function () use ($seal, $raw, &$encrypted): void {
            $encrypted = $seal($raw);
        });

        foreach ($logs as $event) {
            expect($event->message)->not->toContain($token);
            expect(json_encode($event->context))->not->toContain($token);
        }

        expect($encrypted->envelope())->not->toContain($token)
            ->and($encrypted->keyId())->not->toContain($token);

        $decodedEnvelope = base64_decode($encrypted->envelope(), strict: true);
        expect($decodedEnvelope)->not->toBeFalse()
            ->and($decodedEnvelope)->not->toContain($token);
    });
});

describe('Destination non-leakage gate — public exception is indistinguishable across reasons (LDST-23)', function () {
    it('produces the same errorCode and message for two different rejection reasons', function () {
        $token = DestinationSentinel::token();
        $rawByReason = DestinationSentinel::rejectionUrls($token, destinationLeakSelfHost());
        $seal = destinationLeakSeal();

        $first = null;
        $second = null;

        try {
            $seal($rawByReason['SchemeNotAllowed']);
        } catch (LinksDomainException $e) {
            $first = $e;
        }

        try {
            $seal($rawByReason['SelfHost']);
        } catch (LinksDomainException $e) {
            $second = $e;
        }

        expect($first)->not->toBeNull()
            ->and($second)->not->toBeNull()
            ->and($first->reason())->not->toBe($second->reason()) // genuinely different internal reasons...
            ->and($first->errorCode())->toBe($second->errorCode()) // ...but identical public contract.
            ->and($first->getMessage())->toBe($second->getMessage())
            ->and($first->errorCode())->toBe(LinksDomainException::INVALID_DESTINATION_URL)
            ->and($first->getMessage())->toBe('The destination URL is not allowed.');
    });
});
