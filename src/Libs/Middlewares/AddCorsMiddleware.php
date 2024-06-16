<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface as iMiddleware;
use Psr\Http\Server\RequestHandlerInterface as iHandler;

final class AddCorsMiddleware implements iMiddleware
{
    public function process(iRequest $request, iHandler $handler): iResponse
    {
        $response = $handler->handle($request);

        if (!$response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        if (!$response->hasHeader('Access-Control-Allow-Credentials')) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
