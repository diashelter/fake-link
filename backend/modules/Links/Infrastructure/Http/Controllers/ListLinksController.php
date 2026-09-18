<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Foundation\Application;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Links\Exceptions\InvalidCursor;
use Modules\Links\Infrastructure\Http\Requests\ListLinksRequest;
use Modules\Links\Infrastructure\Http\Responses\LinkErrorResponseFactory;
use Modules\Links\Infrastructure\Http\Responses\LinkResponseFactory;
use Modules\Links\Infrastructure\Telemetry\LinkQueryMetrics;
use Modules\Links\UseCases\ListLinks;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ListLinksController
{
    public function __construct(
        private Application $app,
        private ListLinks $listLinks,
        private LinkResponseFactory $linkResponseFactory,
        private LinkErrorResponseFactory $linkErrorResponseFactory,
        private LinkQueryMetrics $metrics,
    ) {}

    public function __invoke(ListLinksRequest $request): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $page = $this->listLinks->execute(
                $principal->userId(),
                $request->toDto(),
                $request->cursor(),
            );
        } catch (InvalidCursor) {
            $this->metrics->recordFailure(
                LinkQueryMetrics::OPERATION_LIST,
                LinkQueryMetrics::REASON_INVALID_CURSOR,
            );

            return ApiResponse::validationError([
                'cursor' => [
                    [
                        'code' => InvalidCursor::ERROR_CODE,
                        'message' => 'The cursor is invalid or has expired.',
                    ],
                ],
            ]);
        } catch (Throwable) {
            $this->metrics->recordFailure(
                LinkQueryMetrics::OPERATION_LIST,
                LinkQueryMetrics::REASON_INFRASTRUCTURE,
            );

            return $this->linkErrorResponseFactory->serviceUnavailable();
        }

        $this->metrics->recordSuccess(LinkQueryMetrics::OPERATION_LIST);

        return $this->linkResponseFactory->collection($page);
    }
}
