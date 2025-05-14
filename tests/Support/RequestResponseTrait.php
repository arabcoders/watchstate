<?php

namespace Tests\Support;

use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Server\RequestHandlerInterface as iHandler;

trait RequestResponseTrait
{
    protected function getHandler(iResponse|callable|null $response = null): iHandler
    {
        $response ??= new Response(Status::OK);

        return new class($response) implements iHandler {
            private mixed $response;

            public function __construct(iResponse|callable $response)
            {
                $this->response = $response;
            }

            public function handle(iRequest $request): iResponse
            {
                return is_callable($this->response) ? ($this->response)($request) : $this->response;
            }
        };
    }

    protected function getRequest(
        Method|string $method = Method::GET,
        string $uri = '/',
        array $post = [],
        array $query = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        iStream|null $body = null

    ): iRequest {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);

        return $creator->fromArrays(
            server: array_replace_recursive([
                'REQUEST_METHOD' => is_string($method) ? $method : $method->value,
                'SCRIPT_FILENAME' => realpath(__DIR__ . '/../../public/index.php'),
                'REMOTE_ADDR' => '127.0.0.1',
                'REQUEST_URI' => $uri,
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => 80,
                'HTTP_USER_AGENT' => 'WatchState/0.0',
            ], $server),
            headers: array_replace_recursive($server, [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer api_test_token',
            ], $headers),
            cookie: $cookies,
            get: $query,
            post: $post,
            files: $files,
            body: $body,
        );
    }

}
