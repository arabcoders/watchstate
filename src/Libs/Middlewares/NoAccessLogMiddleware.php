<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\Libs\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class NoAccessLogMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (false === (bool)$request->getAttribute('INTERNAL_REQUEST', false)) {
            return $handler->handle($request);
        }

        if (true === (bool)Config::get('api.logInternal', false)) {
            return $handler->handle($request);
        }

        return $handler->handle($request)->withHeader('X-No-AccessLog', '1');
    }
}
