<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Auth\DTOs\Input\RegisterUserDto;
use Modules\Auth\Infrastructure\Http\Rules\PasswordPolicyRule;

final class RegisterUserRequest extends ApiFormRequest
{
    private const ALLOWED_FIELDS = [
        'name',
        'email',
        'password',
        'password_confirmation',
        'accept_terms',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicyRule],
            'password_confirmation' => ['required', 'string'],
            'accept_terms' => ['required', 'boolean', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $extra = array_diff(array_keys($this->all()), self::ALLOWED_FIELDS);

            if ($extra === []) {
                return;
            }

            foreach ($extra as $field) {
                $validator->errors()->add(
                    (string) $field,
                    'The '.$field.' field is not allowed.',
                );
            }
        });
    }

    public function toDto(): RegisterUserDto
    {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $this->safe()->only(['name', 'email', 'password']);

        return new RegisterUserDto(
            name: $validated['name'],
            email: $validated['email'],
            plainTextPassword: $validated['password'],
        );
    }
}
