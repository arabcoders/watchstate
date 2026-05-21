<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\Libs\Container;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface as iMiddleware;
use Psr\Http\Server\RequestHandlerInterface as iHandler;
use Psr\Log\LoggerInterface as iLogger;

final class ExceptionHandlerMiddleware implements iMiddleware
{
    public function __construct(
        private readonly ?iLogger $logger = null,
    ) {}

    public function process(iRequest $request, iHandler $handler): iResponse
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $status = Status::tryFrom($e->getCode()) ?? Status::INTERNAL_SERVER_ERROR;

            $logger = $this->logger;

            if (null === $logger) {
                try {
                    $logger = Container::get(iLogger::class);
                } catch (\Throwable) {
                    $logger = null;
                }
            }

            $logger?->error('API request failed for {request.method} {request.path}.', [
                'event_name' => 'http.request.failed',
                'subsystem' => 'http.request',
                'operation' => 'middleware',
                'outcome' => 'failed',
                'request' => [
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                    'uri' => (string) $request->getUri(),
                ],
                'response' => [
                    'status_code' => $status->value,
                ],
                ...exception_log($e),
            ]);

            return api_error(
                $status->value >= 500 ? 'Request handling failed.' : $e->getMessage(),
                $status,
            );
        }
    }
}
