<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface as iMiddleware;
use Psr\Http\Server\RequestHandlerInterface as iHandler;

final class AddTimingMiddleware implements iMiddleware
{
    public function process(iRequest $request, iHandler $handler): iResponse
    {
        return $handler->handle($request)->withHeader(
            'X-Application-Finished-In',
            round(microtime(true) - APP_START, 6) . 's',
        );
    }
}
