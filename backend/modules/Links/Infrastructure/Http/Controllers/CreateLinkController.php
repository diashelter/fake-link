<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Foundation\Application;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugPolicyException;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Http\Requests\CreateLinkRequest;
use Modules\Links\Infrastructure\Http\Responses\LinkErrorResponseFactory;
use Modules\Links\Infrastructure\Http\Responses\LinkResponseFactory;
use Modules\Links\UseCases\CreateLink;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class CreateLinkController
{
    public function __construct(
        private Application $app,
        private CreateLink $createLink,
        private LinkETag $linkETag,
        private LinkResponseFactory $linkResponseFactory,
        private LinkErrorResponseFactory $linkErrorResponseFactory,
    ) {}

    public function __invoke(CreateLinkRequest $request): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $created = $this->createLink->execute($principal->userId(), $request->toDto());
        } catch (SlugUnavailable $exception) {
            return $this->linkErrorResponseFactory->fromSlugUnavailable($exception);
        } catch (SlugGenerationExhausted $exception) {
            return $this->linkErrorResponseFactory->fromSlugGenerationExhausted(
                $exception,
                (int) config('links.rate_limits.create.decay_seconds', 1),
            );
        } catch (SlugPolicyException) {
            // Reserved-word denylist lives in ReserveSlug; map to the closed INVALID_ALIAS code.
            return ApiResponse::validationError([
                'custom_alias' => [
                    [
                        'code' => 'INVALID_ALIAS',
                        'message' => 'The custom alias is invalid.',
                    ],
                ],
            ]);
        } catch (Throwable) {
            return $this->linkErrorResponseFactory->serviceUnavailable();
        }

        $etag = $this->linkETag->for(
            id: $created->id,
            slug: $created->slug,
            normalizedDestinationUrl: $created->destinationUrl,
            title: $created->title,
            isEnabled: $created->isEnabled,
            expiresAt: $created->expiresAt,
            blockedAt: $created->blockedAt,
            updatedAt: $created->updatedAt,
            effectiveStatus: $created->status,
        );

        return $this->linkResponseFactory->created($created, $etag);
    }
}
