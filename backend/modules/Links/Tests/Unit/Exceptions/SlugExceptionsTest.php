<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugPolicyException;
use Modules\Links\Exceptions\SlugUnavailable;

describe('SlugPolicyException', function () {
    it('carries every rejection reason accessibly via rejectionReason()', function () {
        foreach (SlugRejectionReason::cases() as $reason) {
            $exception = SlugPolicyException::fromReason($reason);

            expect($exception->rejectionReason())->toBe($reason)
                ->and($exception->getMessage())->toContain($reason->value);
        }
    });

    it('is a DomainException', function () {
        expect(SlugPolicyException::fromReason(SlugRejectionReason::ReservedWord))
            ->toBeInstanceOf(DomainException::class);
    });
});

describe('SlugUnavailable', function () {
    it('has a uniform message that never varies with the occupancy reason', function () {
        expect(SlugUnavailable::reserved()->getMessage())
            ->toBe('The requested slug is unavailable.')
            ->and(SlugUnavailable::reserved()->getMessage())
            ->toBe(SlugUnavailable::reserved()->getMessage());
    });

    it('accepts and exposes no data about the occupying reservation', function () {
        $constructor = (new ReflectionMethod(SlugUnavailable::class, 'reserved'));
        $publicMethods = array_map(
            static fn (ReflectionMethod $m): string => strtolower($m->getName()),
            (new ReflectionClass(SlugUnavailable::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        expect($constructor->getNumberOfParameters())->toBe(0)
            ->and($publicMethods)->not->toContain('owner')
            ->and($publicMethods)->not->toContain('reservedat')
            ->and($publicMethods)->not->toContain('title')
            ->and($publicMethods)->not->toContain('destination');
    });
});

describe('SlugGenerationExhausted', function () {
    it('reveals neither an attempt count nor a candidate slug in its message', function () {
        $message = SlugGenerationExhausted::exhausted()->getMessage();

        expect(preg_match('/\d/', $message))->toBe(0)
            ->and($message)->toBe('Slug generation failed: no available slug could be reserved.');
    });

    it('exposes the stable SLUG_GENERATION_FAILED error code and is a DomainException', function () {
        $exception = SlugGenerationExhausted::exhausted();

        expect($exception->errorCode())->toBe('SLUG_GENERATION_FAILED')
            ->and($exception)->toBeInstanceOf(DomainException::class);
    });
});
