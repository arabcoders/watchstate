<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\Libs\Config;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface as iMiddleware;
use Psr\Http\Server\RequestHandlerInterface as iHandler;

final class NoAccessLogMiddleware implements iMiddleware
{
    public function process(iRequest $request, iHandler $handler): iResponse
    {
        if (false === (bool) $request->getAttribute('INTERNAL_REQUEST', false)) {
            return $handler->handle($request);
        }

        if (true === (bool) Config::get('api.logInternal', false)) {
            return $handler->handle($request);
        }

        return $handler->handle($request)->withHeader('X-No-AccessLog', '1');
    }
}
