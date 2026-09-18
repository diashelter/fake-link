<?php

declare(strict_types=1);

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Modules\Auth\Infrastructure\Http\Requests\LoginUserRequest;
use Modules\Auth\Infrastructure\Http\Requests\RegisterUserRequest;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array{code: string, message: string, request_id: string, errors: array<string, list<array{code: string, message: string}>>}
 */
function invokeFailedValidation(ApiFormRequest $request, Validator $validator): array
{
    $method = new ReflectionMethod(ApiFormRequest::class, 'failedValidation');

    $caught = null;

    try {
        $method->invoke($request, $validator);
    } catch (HttpResponseException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(HttpResponseException::class);

    $response = $caught->getResponse();
    expect($response)->toBeInstanceOf(JsonResponse::class);

    /** @var JsonResponse $response */
    /** @var array{code: string, message: string, request_id: string, errors: array<string, list<array{code: string, message: string}>>} $payload */
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(422)
        ->and($payload['code'])->toBe('VALIDATION_FAILED');

    return $payload;
}

/**
 * @param  array<string, string>  $errorCodes
 */
function makeExposableRequest(array $errorCodes = []): ApiFormRequest
{
    $request = new class extends ApiFormRequest
    {
        /** @var array<string, string> */
        public array $mappedErrorCodes = [];

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
                'title' => ['required', 'string', 'max:120'],
            ];
        }

        /**
         * @return array<string, string>
         */
        protected function errorCodes(): array
        {
            return $this->mappedErrorCodes;
        }
    };

    $request->mappedErrorCodes = $errorCodes;

    return $request;
}

describe('ApiFormRequest errorCodes', function () {
    it('defaults every field error code to INVALID when errorCodes is empty', function () {
        $request = makeExposableRequest([]);
        $validator = ValidatorFacade::make([], [
            'email' => ['required'],
            'title' => ['required'],
        ]);
        $validator->fails();

        $payload = invokeFailedValidation($request, $validator);

        expect($payload['errors']['email'][0]['code'])->toBe('INVALID')
            ->and($payload['errors']['title'][0]['code'])->toBe('INVALID');
    });

    it('maps a declared field.rule pair to a stable error code', function () {
        $request = makeExposableRequest([
            'email.required' => 'REQUIRED',
        ]);
        $validator = ValidatorFacade::make([], ['email' => ['required']]);
        $validator->fails();

        $payload = invokeFailedValidation($request, $validator);

        expect($payload['errors']['email'][0]['code'])->toBe('REQUIRED')
            ->and($payload['errors']['email'][0])->toHaveKeys(['code', 'message']);
    });

    it('keeps INVALID for a field.rule pair absent from the map', function () {
        $request = makeExposableRequest([
            'email.required' => 'REQUIRED',
        ]);
        $validator = ValidatorFacade::make(
            ['email' => 'not-an-email'],
            ['email' => ['email']],
        );
        $validator->fails();

        $payload = invokeFailedValidation($request, $validator);

        expect($payload['errors']['email'][0]['code'])->toBe('INVALID');
    });

    it('maps distinct rules on the same field independently', function () {
        $request = makeExposableRequest([
            'title.required' => 'REQUIRED',
            'title.max' => 'TITLE_TOO_LONG',
        ]);

        $requiredValidator = ValidatorFacade::make([], ['title' => ['required', 'max:120']]);
        $requiredValidator->fails();
        $requiredPayload = invokeFailedValidation($request, $requiredValidator);

        $maxValidator = ValidatorFacade::make(
            ['title' => str_repeat('a', 121)],
            ['title' => ['required', 'max:120']],
        );
        $maxValidator->fails();
        $maxPayload = invokeFailedValidation($request, $maxValidator);

        expect($requiredPayload['errors']['title'][0]['code'])->toBe('REQUIRED')
            ->and($maxPayload['errors']['title'][0]['code'])->toBe('TITLE_TOO_LONG');
    });

    it('preserves INVALID for RegisterUserRequest validation failures (Auth regression)', function () {
        $request = RegisterUserRequest::create('/', 'POST', []);
        $validator = ValidatorFacade::make([], [
            'name' => ['required'],
            'email' => ['required'],
            'password' => ['required'],
            'password_confirmation' => ['required'],
            'accept_terms' => ['required'],
        ]);
        $validator->fails();

        $payload = invokeFailedValidation($request, $validator);

        foreach (['name', 'email', 'password', 'password_confirmation', 'accept_terms'] as $field) {
            expect($payload['errors'][$field][0]['code'])->toBe('INVALID');
        }
    });

    it('preserves INVALID for LoginUserRequest validation failures (Auth regression)', function () {
        $request = LoginUserRequest::create('/', 'POST', []);
        $validator = ValidatorFacade::make([], [
            'email' => ['required'],
            'password' => ['required'],
        ]);
        $validator->fails();

        $payload = invokeFailedValidation($request, $validator);

        expect($payload['errors']['email'][0]['code'])->toBe('INVALID')
            ->and($payload['errors']['password'][0]['code'])->toBe('INVALID');
    });

    it('defaults to INVALID when messages are added without a failed rule entry', function () {
        $request = makeExposableRequest([
            'extra.unknown' => 'UNKNOWN_FIELD',
        ]);
        $validator = ValidatorFacade::make(['ok' => 'yes'], ['ok' => ['required']]);
        $validator->after(function (Validator $validator): void {
            $validator->errors()->add('extra', 'The extra field is not allowed.');
        });
        $validator->fails();

        $payload = invokeFailedValidation($request, $validator);

        expect($payload['errors']['extra'][0]['code'])->toBe('INVALID');
    });
});
