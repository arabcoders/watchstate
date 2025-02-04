<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\NoAccessLogMiddleware;
use App\Libs\Response;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

class NoAccessLogMiddlewareTest extends TestCase
{
    use RequestResponseTrait;

    public function test_response_not_internal_request()
    {
        $result = new NoAccessLogMiddleware()->process(
            request: $this->getRequest(),
            handler: $this->getHandler(new Response(Status::OK))
        );

        $this->assertFalse(
            $result->hasHeader('X-No-AccessLog'),
            'If INTERNAL_REQUEST is not set, Logging should be enabled.'
        );
    }

    public function test_response_internal_request()
    {
        Config::save('api.logInternal', true);

        $result = new NoAccessLogMiddleware()->process(
            request: $this->getRequest()->withAttribute('INTERNAL_REQUEST', true),
            handler: $this->getHandler(new Response(Status::OK))
        );

        $this->assertFalse(
            $result->hasHeader('X-No-AccessLog'),
            'If INTERNAL_REQUEST is not set, Logging should be enabled.'
        );

        Config::save('api.logInternal', false);
        $result = new NoAccessLogMiddleware()->process(
            request: $this->getRequest()->withAttribute('INTERNAL_REQUEST', true),
            handler: $this->getHandler(new Response(Status::OK))
        );

        $this->assertTrue(
            $result->hasHeader('X-No-AccessLog'),
            'If INTERNAL_REQUEST is set and api.logInternal is true, Logging should be disabled.'
        );
    }
}
