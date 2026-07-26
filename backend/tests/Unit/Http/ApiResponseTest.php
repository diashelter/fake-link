<?php

declare(strict_types=1);

use App\Http\Requests\ApiFormRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Tests\TestCase;

uses(TestCase::class);

describe('ApiResponse', function () {
    it('builds OpenAPI ValidationError envelope with required fields and headers', function () {
        $errors = [
            'email' => [
                [
                    'code' => 'INVALID',
                    'message' => 'The email field is required.',
                ],
            ],
        ];

        $response = ApiResponse::validationError($errors, 'req-422');
        $payload = $response->getData(true);

        expect($response->getStatusCode())->toBe(422)
            ->and($payload)->toBe([
                'code' => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid.',
                'request_id' => 'req-422',
                'errors' => $errors,
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        expect(array_key_exists('success', $payload))->toBeFalse();
    });

    it('defaults request_id when omitted', function () {
        $response = ApiResponse::validationError([
            'name' => [
                ['code' => 'INVALID', 'message' => 'The name field is required.'],
            ],
        ]);

        $requestId = $response->getData(true)['request_id'];

        expect($requestId)->toBeString();
        expect($requestId)->not->toBe('');
    });

    it('builds OpenAPI MALFORMED_REQUEST envelope with required fields and headers', function () {
        $response = ApiResponse::malformedRequest('req-400');
        $payload = $response->getData(true);

        expect($response->getStatusCode())->toBe(400)
            ->and($payload)->toBe([
                'code' => 'MALFORMED_REQUEST',
                'message' => 'The request is malformed.',
                'request_id' => 'req-400',
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        expect(array_key_exists('success', $payload))->toBeFalse();
    });
});

describe('ApiFormRequest', function () {
    it('failedValidation returns 422 VALIDATION_FAILED OpenAPI envelope', function () {
        $request = new class extends ApiFormRequest
        {
            public function authorize(): bool
            {
                return true;
            }

            /**
             * @return array<string, list<string>>
             */
            public function rules(): array
            {
                return [
                    'email' => ['required', 'email'],
                ];
            }

            public function exposeFailedValidation(Validator $validator): void
            {
                $this->failedValidation($validator);
            }
        };

        $validator = ValidatorFacade::make([], ['email' => ['required']]);
        $caught = null;

        try {
            $request->exposeFailedValidation($validator);
        } catch (HttpResponseException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBeInstanceOf(HttpResponseException::class);

        $response = $caught->getResponse();

        expect($response)->toBeInstanceOf(JsonResponse::class);

        /** @var JsonResponse $response */
        $payload = $response->getData(true);

        expect($response->getStatusCode())->toBe(422)
            ->and($payload['code'])->toBe('VALIDATION_FAILED')
            ->and($payload['message'])->toBe('The given data was invalid.')
            ->and($payload)->toHaveKey('request_id')
            ->and($payload['errors'])->toHaveKey('email')
            ->and($payload['errors']['email'][0])->toHaveKeys(['code', 'message'])
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});
