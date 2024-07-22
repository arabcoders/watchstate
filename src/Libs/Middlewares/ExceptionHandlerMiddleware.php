<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface as iMiddleware;
use Psr\Http\Server\RequestHandlerInterface as iHandler;

final class ExceptionHandlerMiddleware implements iMiddleware
{
    public function process(iRequest $request, iHandler $handler): iResponse
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return api_error($e->getMessage(), $e->getCode());
        }
    }
}
