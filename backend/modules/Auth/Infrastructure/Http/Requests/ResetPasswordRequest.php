<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Auth\DTOs\Input\ResetPasswordDto;
use Modules\Auth\Infrastructure\Http\Rules\PasswordPolicyRule;

final class ResetPasswordRequest extends ApiFormRequest
{
    private const ALLOWED_FIELDS = [
        'email',
        'token',
        'password',
        'password_confirmation',
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
            'token' => ['required', 'string', 'min:1'],
            'password' => ['required', 'string', 'confirmed', new PasswordPolicyRule],
            'password_confirmation' => ['required', 'string'],
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

    public function toDto(): ResetPasswordDto
    {
        /** @var array{email: string, token: string, password: string} $validated */
        $validated = $this->safe()->only(['email', 'token', 'password']);

        return new ResetPasswordDto(
            email: $validated['email'],
            plainTextToken: $validated['token'],
            plainTextPassword: $validated['password'],
        );
    }
}
