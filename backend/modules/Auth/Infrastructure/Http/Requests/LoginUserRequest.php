<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Auth\DTOs\Input\LoginUserDto;

final class LoginUserRequest extends ApiFormRequest
{
    private const ALLOWED_FIELDS = [
        'email',
        'password',
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
        $this->replace($this->only(self::ALLOWED_FIELDS));

        if (is_string($this->input('email'))) {
            $this->merge([
                'email' => strtolower(trim($this->input('email'))),
            ]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
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

    public function toDto(): LoginUserDto
    {
        /** @var array{email: string, password: string} $validated */
        $validated = $this->safe()->only(['email', 'password']);

        return new LoginUserDto(
            email: $validated['email'],
            plainTextPassword: $validated['password'],
        );
    }
}
