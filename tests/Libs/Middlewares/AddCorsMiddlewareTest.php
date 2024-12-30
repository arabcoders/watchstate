<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\AddCorsMiddleware;
use App\Libs\Response;
use App\Libs\TestCase;
use Tests\Support\RequestResponseTrait;

class AddCorsMiddlewareTest extends TestCase
{
    use RequestResponseTrait;

    public function test_response()
    {
        $result = new AddCorsMiddleware()->process(
            request: $this->getRequest(),
            handler: $this->getHandler(new Response(Status::OK))
        );

        $this->assertTrue(
            $result->hasHeader('Access-Control-Allow-Origin'),
            'Access-Control-Allow-Origin is not available'
        );

        $this->assertTrue(
            $result->hasHeader('Access-Control-Allow-Credentials'),
            'Access-Control-Allow-Credentials is not available'
        );
    }
}
