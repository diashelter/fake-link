<?php

declare(strict_types=1);

use DateTimeImmutable;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use ReflectionClass;

describe('CreateLinkInput', function () {
    it('is a final readonly carrier of creation payload fields without logic', function () {
        $expiresAt = new DateTimeImmutable('2026-12-01T00:00:00Z');
        $input = new CreateLinkInput(
            destinationUrl: 'https://example.com/a',
            customAlias: 'Architecture',
            title: 'Launch',
            expiresAt: $expiresAt,
        );

        expect($input)->toBeInstanceOf(CreateLinkInput::class)
            ->and($input->destinationUrl)->toBe('https://example.com/a')
            ->and($input->customAlias)->toBe('Architecture')
            ->and($input->title)->toBe('Launch')
            ->and($input->expiresAt)->toBe($expiresAt)
            ->and((new ReflectionClass(CreateLinkInput::class))->isFinal())->toBeTrue()
            ->and((new ReflectionClass(CreateLinkInput::class))->isReadOnly())->toBeTrue();
    });
});

describe('CreatedLinkDto', function () {
    it('is a final readonly carrier including version and blockedAt for ETag', function () {
        $createdAt = new DateTimeImmutable('2025-01-01T12:00:00Z');
        $dto = new CreatedLinkDto(
            id: '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            slug: 'architecture',
            slugSource: SlugSource::Custom,
            destinationUrl: 'https://example.com/a',
            title: 'Launch',
            isEnabled: true,
            status: LinkStatus::Active,
            expiresAt: null,
            blockedAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            version: 1,
        );

        expect($dto->version)->toBe(1)
            ->and($dto->blockedAt)->toBeNull()
            ->and($dto->slugSource)->toBe(SlugSource::Custom)
            ->and($dto->status)->toBe(LinkStatus::Active)
            ->and((new ReflectionClass(CreatedLinkDto::class))->isFinal())->toBeTrue()
            ->and((new ReflectionClass(CreatedLinkDto::class))->isReadOnly())->toBeTrue();
    });

    it('carries enough state to seal an ETag and to serialize LinkDetail fields', function () {
        $createdAt = new DateTimeImmutable('2025-01-01T12:00:00Z');
        $dto = new CreatedLinkDto(
            id: '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            slug: 'abc12345',
            slugSource: SlugSource::Automatic,
            destinationUrl: 'https://example.com/path',
            title: null,
            isEnabled: true,
            status: LinkStatus::Active,
            expiresAt: null,
            blockedAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            version: 1,
        );

        $signingKey = new class implements ETagSigningKey
        {
            public function value(): string
            {
                return 'dto-etag-key';
            }
        };

        $etag = (new LinkETag($signingKey))->for(
            id: $dto->id,
            slug: $dto->slug,
            normalizedDestinationUrl: $dto->destinationUrl,
            title: $dto->title,
            isEnabled: $dto->isEnabled,
            expiresAt: $dto->expiresAt,
            blockedAt: $dto->blockedAt,
            updatedAt: $dto->updatedAt,
            effectiveStatus: $dto->status,
        );

        $linkDetailKeys = [
            'id' => $dto->id,
            'slug' => $dto->slug,
            'destination_url' => $dto->destinationUrl,
            'title' => $dto->title,
            'slug_source' => $dto->slugSource->value,
            'is_enabled' => $dto->isEnabled,
            'status' => $dto->status->value,
            'expires_at' => $dto->expiresAt,
            'created_at' => $dto->createdAt,
            'updated_at' => $dto->updatedAt,
        ];

        expect($etag)->toMatch('/^"[^"]+"$/')
            ->and(array_keys($linkDetailKeys))->toBe([
                'id',
                'slug',
                'destination_url',
                'title',
                'slug_source',
                'is_enabled',
                'status',
                'expires_at',
                'created_at',
                'updated_at',
            ])
            ->and($dto->version)->toBe(1)
            ->and($dto->blockedAt)->toBeNull();
    });
});
