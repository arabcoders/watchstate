<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class ParseJsonBodyMiddleware implements MiddlewareInterface
{
    private array $nonBodyRequests = [
        'GET',
        'HEAD',
        'OPTIONS',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), $this->nonBodyRequests)) {
            return $handler->handle($request);
        }

        $header = $request->getHeaderLine('Content-Type');

        if (1 === preg_match('#^application/(|\S+\+)json($|[ ;])#', $header)) {
            return $handler->handle($this->parse($request));
        }

        return $handler->handle($request);
    }

    private function parse(ServerRequestInterface $request): ServerRequestInterface
    {
        $rawBody = (string)$request->getBody();

        if (empty($rawBody)) {
            return $request->withAttribute('rawBody', $rawBody)->withParsedBody(null);
        }

        $parsedBody = json_decode($rawBody, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException(sprintf('Error when parsing JSON request body: %s', json_last_error_msg()));
        }

        return $request->withAttribute('rawBody', $rawBody)->withParsedBody($parsedBody);
    }
}
