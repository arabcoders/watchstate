<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\Libs\Middlewares\ExceptionHandlerMiddleware;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

class ExceptionHandlerMiddlewareTest extends TestCase
{
    use RequestResponseTrait;

    /** @noinspection PhpUnhandledExceptionInspection */
    public function test_response()
    {
        $result = (new ExceptionHandlerMiddleware())->process(
            request: $this->getRequest(),
            handler: $this->getHandler(
                fn() => throw new \RuntimeException('Test Exception', 404)
            )
        );

        $json = json_decode($result->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('error', $json, 'Error key is not available');
        $this->assertArrayHasKey('message', $json['error'], 'Message key is not available');
        $this->assertArrayHasKey('code', $json['error'], 'Status key is not available');

        $this->assertSame('Test Exception', $json['error']['message'], 'Message is not equal');
        $this->assertSame(404, $json['error']['code'], 'Status is not equal');
    }
}
