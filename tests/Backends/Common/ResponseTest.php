<?php

declare(strict_types=1);

namespace Tests\Backends\Common;

use App\Backends\Common\Error;
use App\Libs\TestCase;

class ResponseTest extends TestCase
{
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

}
