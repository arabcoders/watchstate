<?php

declare(strict_types=1);

namespace Tests\Backends\Common;

use App\Backends\Common\Error;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\TestCase;
use App\Libs\Uri;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class ResponseTest extends TestCase
{
    use \App\Backends\Common\CommonTrait;

    public function test_backend_response_object(): void
    {
        $response = new \App\Backends\Common\Response(
            status: true,
            response: 'Hello World!',
            error: null,
            extra: [
                'foo' => 'bar'
            ],
        );

        $this->assertTrue(
            $response->isSuccessful(),
            'Response object should be successful if status is true.'
        );

        $this->assertFalse(
            $response->hasError(),
            'Response object should not have an error if error is null.'
        );

        $this->assertEquals(
            'Hello World!',
            $response->response,
            'Response object should have the same response as the one passed in the constructor.'
        );

        $this->assertEquals(
            ['foo' => 'bar'],
            $response->extra,
            'Response object should have the same extra as the one passed in the constructor.'
        );

        $this->assertInstanceOf(
            Error::class,
            $response->getError(),
            'getError() should return an Error object in all cases even if error is null.'
        );
    }

    public function test_tryResponse(): void
    {
        $context = new \App\Backends\Common\Context(
            clientName: 'test',
            backendName: 'test',
            backendUrl: new Uri('https://example.com'),
            cache: new \App\Backends\Common\Cache(
                logger: new Logger('test', [new NullHandler()]),
                cache: new \Symfony\Component\Cache\Psr16Cache(
                    new \Symfony\Component\Cache\Adapter\NullAdapter()
                ),
            ),
            trace: false,
        );

        $response = (fn() => $this->tryResponse($context, fn() => throw new RuntimeException('test', 500)))();

        $this->assertTrue(
            $response->hasError(),
            'Response object should not have an error if error is null.'
        );

        $this->assertNull(
            $response->response,
            'Response object should have the same response as the one passed in the constructor.'
        );

        $this->assertInstanceOf(
            Error::class,
            $response->getError(),
            'getError() should return an Error object in all cases even if error is null.'
        );

        $response = (fn() => $this->tryResponse($context, fn() => 'i am teapot'))();

        $this->assertSame(
            'i am teapot',
            $response->response,
            'Response object should have the same response as the one passed in the constructor.'
        );
    }

}
