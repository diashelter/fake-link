<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Telemetry;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Low-cardinality counters for link creation outcomes.
 *
 * Labels are an allowlist of outcome/reason enums only — never slug, alias,
 * destination_url, query, fragment, title, or account identifiers.
 */
final class LinkCreationMetrics
{
    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const REASON_ALIAS_UNAVAILABLE = 'alias_unavailable';

    public const REASON_SLUG_EXHAUSTED = 'slug_exhausted';

    public const REASON_VALIDATION_FAILED = 'validation_failed';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_INFRASTRUCTURE = 'infrastructure';

    private const ALLOWED_REASONS = [
        self::REASON_ALIAS_UNAVAILABLE,
        self::REASON_SLUG_EXHAUSTED,
        self::REASON_VALIDATION_FAILED,
        self::REASON_RATE_LIMITED,
        self::REASON_INFRASTRUCTURE,
    ];

    /**
     * @var list<array{metric: string, labels: array<string, string>}>
     */
    private array $recorded = [];

    /**
     * @var list<array{name: string, attributes: array<string, string>}>
     */
    private array $traces = [];

    public function recordSuccess(): void
    {
        $this->emit('links.create', [
            'result' => self::RESULT_SUCCESS,
        ]);

        $this->trace('links.create', [
            'result' => self::RESULT_SUCCESS,
        ]);
    }

    public function recordFailure(string $reason): void
    {
        if (! in_array($reason, self::ALLOWED_REASONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported link-creation failure reason "%s".',
                $reason,
            ));
        }

        $this->emit('links.create', [
            'result' => self::RESULT_FAILURE,
            'reason' => $reason,
        ]);

        $this->trace('links.create', [
            'result' => self::RESULT_FAILURE,
            'reason' => $reason,
        ]);
    }

    /**
     * @return list<array{metric: string, labels: array<string, string>}>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * @return list<array{name: string, attributes: array<string, string>}>
     */
    public function traces(): array
    {
        return $this->traces;
    }

    public function reset(): void
    {
        $this->recorded = [];
        $this->traces = [];
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function emit(string $metric, array $labels): void
    {
        $this->assertSafeLabels($labels);

        $this->recorded[] = [
            'metric' => $metric,
            'labels' => $labels,
        ];

        Log::info('links.create.metric', [
            'metric' => $metric,
            'labels' => $labels,
        ]);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function trace(string $name, array $attributes): void
    {
        $this->assertSafeLabels($attributes);

        $this->traces[] = [
            'name' => $name,
            'attributes' => $attributes,
        ];

        Log::info('links.create.trace', [
            'name' => $name,
            'attributes' => $attributes,
        ]);
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function assertSafeLabels(array $labels): void
    {
        $forbiddenKeys = [
            'slug',
            'alias',
            'custom_alias',
            'destination_url',
            'url',
            'query',
            'fragment',
            'title',
            'user_id',
            'email',
        ];

        foreach (array_keys($labels) as $key) {
            if (in_array(strtolower($key), $forbiddenKeys, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Forbidden telemetry label "%s".',
                    $key,
                ));
            }
        }
    }
}
