<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Exceptions\DestinationDecryptionFailed;
use Modules\Links\Exceptions\LinksDomainException;
use Modules\Links\Infrastructure\Http\Responses\LinkErrorResponseFactory;
use Modules\Links\Infrastructure\Http\Responses\LinkResponseFactory;
use Modules\Links\Infrastructure\Telemetry\LinkQueryMetrics;
use Modules\Links\UseCases\GetLink;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class GetLinkController
{
    public function __construct(
        private Application $app,
        private GetLink $getLink,
        private LinkResponseFactory $linkResponseFactory,
        private LinkErrorResponseFactory $linkErrorResponseFactory,
        private LinkQueryMetrics $metrics,
    ) {}

    public function __invoke(string $link): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $linkId = ShortLinkId::fromString($link);
        } catch (LinksDomainException) {
            $this->metrics->recordFailure(
                LinkQueryMetrics::OPERATION_DETAIL,
                LinkQueryMetrics::REASON_NOT_FOUND,
            );

            return $this->linkErrorResponseFactory->notFound();
        }

        try {
            $result = $this->getLink->execute($principal->userId(), $linkId);
        } catch (DestinationDecryptionFailed) {
            $this->metrics->recordFailure(
                LinkQueryMetrics::OPERATION_DETAIL,
                LinkQueryMetrics::REASON_DECRYPT_FAILED,
            );

            return $this->linkErrorResponseFactory->serviceUnavailable();
        } catch (Throwable) {
            $this->metrics->recordFailure(
                LinkQueryMetrics::OPERATION_DETAIL,
                LinkQueryMetrics::REASON_INFRASTRUCTURE,
            );

            return $this->linkErrorResponseFactory->serviceUnavailable();
        }

        if ($result === null) {
            $this->metrics->recordFailure(
                LinkQueryMetrics::OPERATION_DETAIL,
                LinkQueryMetrics::REASON_NOT_FOUND,
            );

            return $this->linkErrorResponseFactory->notFound();
        }

        $this->metrics->recordSuccess(LinkQueryMetrics::OPERATION_DETAIL);

        return $this->linkResponseFactory->detail($result);
    }
}
