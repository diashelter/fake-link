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
     * Optional map of "field.rule" → stable error code.
     * Rule names use lowercase Laravel identifiers (e.g. "required", "email").
     * Unmapped field/rule pairs keep the default code "INVALID".
     *
     * @return array<string, string>
     */
    protected function errorCodes(): array
    {
        return [];
    }

    /**
     * @return array<string, list<array{code: string, message: string}>>
     */
    private function formatValidationErrors(Validator $validator): array
    {
        $formatted = [];
        $errorCodes = $this->errorCodes();
        $failed = $validator->failed();

        foreach ($validator->errors()->messages() as $field => $messages) {
            $failedRules = array_keys($failed[$field] ?? []);
            $formatted[$field] = [];

            foreach (array_values($messages) as $index => $message) {
                $code = 'INVALID';

                if (isset($failedRules[$index])) {
                    $ruleKey = $this->normalizeFailedRuleName($failedRules[$index]);
                    $mapKey = $field.'.'.$ruleKey;

                    if (isset($errorCodes[$mapKey])) {
                        $code = $errorCodes[$mapKey];
                    }
                }

                $formatted[$field][] = [
                    'code' => $code,
                    'message' => $message,
                ];
            }
        }

        return $formatted;
    }

    private function normalizeFailedRuleName(string $failedRule): string
    {
        if (str_contains($failedRule, '\\')) {
            return strtolower(class_basename($failedRule));
        }

        return strtolower($failedRule);
    }
}
