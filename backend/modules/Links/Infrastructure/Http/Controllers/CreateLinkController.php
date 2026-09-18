<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Foundation\Application;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Links\Exceptions\IdempotencyKeyReused;
use Modules\Links\Exceptions\IdempotencySnapshotDecryptionFailed;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugPolicyException;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Http\Requests\CreateLinkRequest;
use Modules\Links\Infrastructure\Http\Responses\LinkErrorResponseFactory;
use Modules\Links\Infrastructure\Http\Responses\LinkResponseFactory;
use Modules\Links\Infrastructure\Telemetry\LinkCreationMetrics;
use Modules\Links\UseCases\CreateIdempotentLink;
use Modules\Links\UseCases\CreateLink;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class CreateLinkController
{
    public function __construct(
        private Application $app,
        private CreateLink $createLink,
        private CreateIdempotentLink $createIdempotentLink,
        private LinkResponseFactory $linkResponseFactory,
        private LinkErrorResponseFactory $linkErrorResponseFactory,
        private LinkCreationMetrics $metrics,
    ) {}

    public function __invoke(CreateLinkRequest $request): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);
        $idempotencyKey = $request->idempotencyKey();

        try {
            if ($idempotencyKey === null) {
                $created = $this->createLink->execute($principal->userId(), $request->toDto());

                $this->metrics->recordSuccess();

                return $this->linkResponseFactory->created($created);
            }

            $result = $this->createIdempotentLink->execute(
                $principal->userId(),
                $request->toDto(),
                $idempotencyKey,
            );
        } catch (SlugUnavailable $exception) {
            $this->metrics->recordFailure(LinkCreationMetrics::REASON_ALIAS_UNAVAILABLE);

            return $this->linkErrorResponseFactory->fromSlugUnavailable($exception);
        } catch (SlugGenerationExhausted $exception) {
            $this->metrics->recordFailure(LinkCreationMetrics::REASON_SLUG_EXHAUSTED);

            return $this->linkErrorResponseFactory->fromSlugGenerationExhausted(
                $exception,
                (int) config('links.rate_limits.create.decay_seconds', 1),
            );
        } catch (SlugPolicyException) {
            $this->metrics->recordFailure(LinkCreationMetrics::REASON_VALIDATION_FAILED);

            // Reserved-word denylist lives in ReserveSlug; map to the closed INVALID_ALIAS code.
            return ApiResponse::validationError([
                'custom_alias' => [
                    [
                        'code' => 'INVALID_ALIAS',
                        'message' => 'The custom alias is invalid.',
                    ],
                ],
            ]);
        } catch (IdempotencyKeyReused $exception) {
            $this->metrics->recordFailure(LinkCreationMetrics::REASON_VALIDATION_FAILED);

            return $this->linkErrorResponseFactory->idempotencyKeyReused($exception);
        } catch (IdempotencySnapshotDecryptionFailed) {
            $this->metrics->recordFailure(LinkCreationMetrics::REASON_INFRASTRUCTURE);

            return $this->linkErrorResponseFactory->serviceUnavailable();
        } catch (Throwable) {
            $this->metrics->recordFailure(LinkCreationMetrics::REASON_INFRASTRUCTURE);

            return $this->linkErrorResponseFactory->serviceUnavailable();
        }

        $this->metrics->recordSuccess();

        return $this->linkResponseFactory->fromSnapshot($result->snapshot);
    }
}
