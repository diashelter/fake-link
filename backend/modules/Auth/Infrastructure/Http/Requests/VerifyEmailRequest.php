<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\DTOs\Input\VerifyUserEmailDto;

final class VerifyEmailRequest extends ApiFormRequest
{
    private const ALLOWED_FIELDS = [
        'token',
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
            'token' => ['required', 'string', 'min:1', 'not_regex:/^\s+$/'],
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

    public function toDto(AuthenticatedPrincipal $principal): VerifyUserEmailDto
    {
        /** @var array{token: string} $validated */
        $validated = $this->safe()->only(['token']);

        return new VerifyUserEmailDto(
            principal: $principal,
            plainTextEmailToken: $validated['token'],
        );
    }
}
