<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            ApiResponse::validationError(
                errors: $this->formatValidationErrors($validator),
            ),
        );
    }

    /**
     * @return array<string, list<array{code: string, message: string}>>
     */
    private function formatValidationErrors(Validator $validator): array
    {
        $formatted = [];

        foreach ($validator->errors()->messages() as $field => $messages) {
            $formatted[$field] = array_map(
                static fn (string $message): array => [
                    'code' => 'INVALID',
                    'message' => $message,
                ],
                $messages,
            );
        }

        return $formatted;
    }
}
