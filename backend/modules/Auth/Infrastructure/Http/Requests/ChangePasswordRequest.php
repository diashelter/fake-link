<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Auth\DTOs\Input\ChangePasswordDto;
use Modules\Auth\Infrastructure\Http\Rules\PasswordPolicyRule;

final class ChangePasswordRequest extends ApiFormRequest
{
    private const ALLOWED_FIELDS = [
        'current_password',
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
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128'],
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

    public function toDto(): ChangePasswordDto
    {
        /** @var array{current_password: string, password: string} $validated */
        $validated = $this->safe()->only(['current_password', 'password']);

        return new ChangePasswordDto(
            currentPassword: $validated['current_password'],
            plainTextPassword: $validated['password'],
        );
    }
}
