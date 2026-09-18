<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Telemetry;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Low-cardinality counters for private link-query outcomes.
 *
 * Labels are an allowlist of operation/result/reason enums only — never token,
 * cursor, slug, title, destination, or account identifiers.
 */
final class LinkQueryMetrics
{
    public const OPERATION_LIST = 'list';

    public const OPERATION_DETAIL = 'detail';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const REASON_VALIDATION_FAILED = 'validation_failed';

    public const REASON_INVALID_CURSOR = 'invalid_cursor';

    public const REASON_RATE_LIMITED = 'rate_limited';

    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_DECRYPT_FAILED = 'decrypt_failed';

    public const REASON_INFRASTRUCTURE = 'infrastructure';

    private const ALLOWED_OPERATIONS = [
        self::OPERATION_LIST,
        self::OPERATION_DETAIL,
    ];

    private const ALLOWED_REASONS = [
        self::REASON_VALIDATION_FAILED,
        self::REASON_INVALID_CURSOR,
        self::REASON_RATE_LIMITED,
        self::REASON_NOT_FOUND,
        self::REASON_DECRYPT_FAILED,
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

    public function recordSuccess(string $operation): void
    {
        $this->assertOperation($operation);

        $this->emit('links.query', [
            'operation' => $operation,
            'result' => self::RESULT_SUCCESS,
        ]);

        $this->trace('links.query', [
            'operation' => $operation,
            'result' => self::RESULT_SUCCESS,
        ]);
    }

    public function recordFailure(string $operation, string $reason): void
    {
        $this->assertOperation($operation);

        if (! in_array($reason, self::ALLOWED_REASONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported link-query failure reason "%s".',
                $reason,
            ));
        }

        $this->emit('links.query', [
            'operation' => $operation,
            'result' => self::RESULT_FAILURE,
            'reason' => $reason,
        ]);

        $this->trace('links.query', [
            'operation' => $operation,
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

        Log::info('links.query.metric', [
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

        Log::info('links.query.trace', [
            'name' => $name,
            'attributes' => $attributes,
        ]);
    }

    private function assertOperation(string $operation): void
    {
        if (! in_array($operation, self::ALLOWED_OPERATIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported link-query operation "%s".',
                $operation,
            ));
        }
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function assertSafeLabels(array $labels): void
    {
        $forbiddenKeys = [
            'token',
            'cursor',
            'slug',
            'title',
            'destination',
            'destination_url',
            'alias',
            'custom_alias',
            'url',
            'query',
            'fragment',
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
