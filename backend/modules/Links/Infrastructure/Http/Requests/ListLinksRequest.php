<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\Infrastructure\Telemetry\LinkQueryMetrics;

final class ListLinksRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        $this->container->make(LinkQueryMetrics::class)
            ->recordFailure(
                LinkQueryMetrics::OPERATION_LIST,
                LinkQueryMetrics::REASON_VALIDATION_FAILED,
            );

        parent::failedValidation($validator);
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        foreach (['cursor', 'per_page', 'search', 'status'] as $key) {
            if ($this->query->has($key)) {
                $payload[$key] = $this->query->get($key);
            }
        }

        foreach (['search', 'cursor'] as $textField) {
            if (! array_key_exists($textField, $payload)) {
                continue;
            }

            $payload[$textField] = trim((string) $payload[$textField]);
        }

        $this->replace($payload);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'status' => ['sometimes', 'string', 'in:active,inactive,expired,blocked,all'],
            'cursor' => ['sometimes', 'string', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function errorCodes(): array
    {
        return [
            'cursor.min' => 'INVALID_CURSOR',
            'cursor.string' => 'INVALID_CURSOR',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cursor.min' => 'The cursor is invalid or has expired.',
            'cursor.string' => 'The cursor is invalid or has expired.',
        ];
    }

    public function toDto(): ListLinksQuery
    {
        /** @var array{per_page?: int|string, search?: string, status?: string, cursor?: string} $validated */
        $validated = $this->validated();

        $perPage = null;

        if (array_key_exists('per_page', $validated)) {
            $perPage = (int) $validated['per_page'];
        }

        return ListLinksQuery::from(
            perPage: $perPage,
            search: $validated['search'] ?? null,
            status: $validated['status'] ?? null,
        );
    }

    public function cursor(): ?string
    {
        /** @var array{cursor?: string} $validated */
        $validated = $this->validated();

        return $validated['cursor'] ?? null;
    }
}
