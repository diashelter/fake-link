<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Auth\DTOs\Input\UpdateCurrentUserDto;

final class UpdateCurrentUserRequest extends ApiFormRequest
{
    private const ALLOWED_FIELDS = [
        'name',
    ];

    /**
     * @var list<string>
     */
    private array $submittedKeys = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->submittedKeys = array_keys($this->all());

        $payload = $this->only(self::ALLOWED_FIELDS);

        if (array_key_exists('name', $payload) && is_string($payload['name'])) {
            $payload['name'] = trim($payload['name']);
        }

        $this->replace($payload);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $extra = array_diff($this->submittedKeys, self::ALLOWED_FIELDS);

            if ($extra === []) {
                return;
            }

            foreach ($extra as $field) {
                $validator->errors()->add(
                    $field,
                    'The '.$field.' field is not allowed.',
                );
            }
        });
    }

    public function toDto(): UpdateCurrentUserDto
    {
        /** @var array{name: string} $validated */
        $validated = $this->safe()->only(['name']);

        return new UpdateCurrentUserDto(
            name: $validated['name'],
        );
    }
}
