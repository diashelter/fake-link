<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Validator as IlluminateValidator;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\IdempotencyKey;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\Exceptions\LinksDomainException;
use Modules\Links\Exceptions\SlugPolicyException;
use Modules\Links\Infrastructure\Telemetry\LinkCreationMetrics;
use Throwable;

final class CreateLinkRequest extends ApiFormRequest
{
    private const ALLOWED_FIELDS = [
        'destination_url',
        'custom_alias',
        'title',
        'expires_at',
    ];

    private const ISO_Z_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /**
     * @var list<string>
     */
    private array $submittedKeys = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        $this->container->make(LinkCreationMetrics::class)
            ->recordFailure(LinkCreationMetrics::REASON_VALIDATION_FAILED);

        parent::failedValidation($validator);
    }

    protected function prepareForValidation(): void
    {
        $this->submittedKeys = array_keys($this->all());

        $payload = $this->only(self::ALLOWED_FIELDS);

        if (array_key_exists('title', $payload) && is_string($payload['title'])) {
            $trimmed = trim($payload['title']);
            $payload['title'] = $trimmed === '' ? null : $trimmed;
        }

        $this->replace($payload);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'destination_url' => ['required', 'string'],
            'custom_alias' => ['sometimes'],
            'title' => ['sometimes', 'nullable', 'string'],
            'expires_at' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function errorCodes(): array
    {
        $codes = [
            'destination_url.required' => 'REQUIRED',
            'destination_url.invaliddestinationurl' => 'INVALID_DESTINATION_URL',
            'custom_alias.invalidalias' => 'INVALID_ALIAS',
            'title.titletoolong' => 'TITLE_TOO_LONG',
            'expires_at.invaliddatetime' => 'INVALID_DATETIME',
            'expires_at.expiresatnotinfuture' => 'EXPIRES_AT_NOT_IN_FUTURE',
            'Idempotency-Key.invalididempotencykey' => 'INVALID_IDEMPOTENCY_KEY',
        ];

        foreach ($this->submittedKeys as $key) {
            if (! in_array($key, self::ALLOWED_FIELDS, true)) {
                $codes[$key.'.unknownfield'] = 'UNKNOWN_FIELD';
            }
        }

        return $codes;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var IlluminateValidator $validator */
            $extra = array_diff($this->submittedKeys, self::ALLOWED_FIELDS);

            foreach ($extra as $field) {
                $validator->addFailure($field, 'UnknownField');
            }

            if ($validator->errors()->has('destination_url')) {
                // Presence/type already failed — do not echo URL via secondary checks.
            } elseif ($this->filled('destination_url') && is_string($this->input('destination_url'))) {
                try {
                    DestinationUrl::fromString(
                        $this->input('destination_url'),
                        $this->container->make(PublicHostClassifier::class),
                    );
                } catch (Throwable) {
                    $validator->addFailure('destination_url', 'InvalidDestinationUrl');
                }
            }

            if (array_key_exists('custom_alias', $this->all())) {
                $alias = $this->input('custom_alias');

                if ($alias === null || ! is_string($alias)) {
                    $validator->addFailure('custom_alias', 'InvalidAlias');
                } else {
                    try {
                        // Structural validation only — denylist belongs to ReserveSlug.
                        Slug::fromCustomAlias($alias);
                    } catch (SlugPolicyException) {
                        $validator->addFailure('custom_alias', 'InvalidAlias');
                    }
                }
            }

            if ($this->has('title') && is_string($this->input('title'))) {
                $title = $this->input('title');

                if (mb_strlen($title) > 160) {
                    $validator->addFailure('title', 'TitleTooLong');
                }
            }

            if ($this->filled('expires_at')) {
                $raw = $this->input('expires_at');

                if (! is_string($raw) || preg_match(self::ISO_Z_PATTERN, $raw) !== 1) {
                    $validator->addFailure('expires_at', 'InvalidDatetime');
                } else {
                    $expiresAt = DateTimeImmutable::createFromFormat(
                        'Y-m-d\TH:i:s\Z',
                        $raw,
                        new DateTimeZone('UTC'),
                    );

                    if ($expiresAt === false) {
                        $validator->addFailure('expires_at', 'InvalidDatetime');
                    } elseif ($expiresAt <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                        $validator->addFailure('expires_at', 'ExpiresAtNotInFuture');
                    }
                }
            }

            $idempotencyHeader = $this->headers->get('Idempotency-Key');

            if ($idempotencyHeader !== null && $idempotencyHeader !== '') {
                try {
                    IdempotencyKey::fromString($idempotencyHeader);
                } catch (LinksDomainException) {
                    $validator->addFailure('Idempotency-Key', 'InvalidIdempotencyKey');
                }
            }
        });
    }

    public function idempotencyKey(): ?IdempotencyKey
    {
        $raw = $this->headers->get('Idempotency-Key');

        if ($raw === null || $raw === '') {
            return null;
        }

        return IdempotencyKey::fromString($raw);
    }

    public function toDto(): CreateLinkInput
    {
        /** @var array{
         *     destination_url: string,
         *     custom_alias?: string,
         *     title?: string|null,
         *     expires_at?: string|null
         * } $validated
         */
        $validated = $this->validated();

        $expiresAt = null;

        if (isset($validated['expires_at']) && $validated['expires_at'] !== '') {
            $expiresAt = DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i:s\Z',
                $validated['expires_at'],
                new DateTimeZone('UTC'),
            );

            if ($expiresAt === false) {
                $expiresAt = null;
            }
        }

        return new CreateLinkInput(
            destinationUrl: $validated['destination_url'],
            customAlias: $validated['custom_alias'] ?? null,
            title: array_key_exists('title', $validated) ? $validated['title'] : null,
            expiresAt: $expiresAt,
        );
    }
}
