<?php

declare(strict_types=1);

namespace App\Libs\Middlewares;

use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use JsonException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Server\MiddlewareInterface as iMiddleware;
use Psr\Http\Server\RequestHandlerInterface as iHandler;
use RuntimeException;

class ParseJsonBodyMiddleware implements iMiddleware
{
    public function process(iRequest $request, iHandler $handler): iResponse
    {
        if (null === ($method = Method::tryFrom($request->getMethod()))) {
            throw new RuntimeException(r('Invalid HTTP method. "{method}".', [
                'method' => $request->getMethod(),
            ]), Status::METHOD_NOT_ALLOWED->value);
        }

        if (true === in_array($method, [Method::GET, Method::HEAD, Method::OPTIONS], true)) {
            return $handler->handle($request);
        }

        $header = $request->getHeaderLine('Content-Type');

        if (1 === preg_match('#^application/(|\S+\+)json($|[ ;])#', $header)) {
            return $handler->handle($this->parse($request));
        }

        return $handler->handle($request);
    }

    private function parse(iRequest $request): iRequest
    {
        $body = (string) $request->getBody();

        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }

        if (empty($body)) {
            return $request;
        }

        try {
            return $request->withParsedBody(json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw new RuntimeException(
                r('Error when parsing JSON request body. {error}', [
                    'error' => $e->getMessage(),
                ]),
                Status::BAD_REQUEST->value,
                $e,
            );
        }
    }
}
