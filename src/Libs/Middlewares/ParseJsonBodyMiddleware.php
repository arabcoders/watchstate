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
        $body = (string)$request->getBody();

        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }

        if (empty($body)) {
            return $request;
        }

        try {
            return $request->withParsedBody(json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            throw new RuntimeException(r('Error when parsing JSON request body. {error}', [
                'error' => $e->getMessage()
            ]), $e->getCode(), $e);
        }
    }
}
