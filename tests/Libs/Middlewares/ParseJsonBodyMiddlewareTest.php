<?php

declare(strict_types=1);

namespace Tests\Libs\Middlewares;

use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Middlewares\ParseJsonBodyMiddleware;
use App\Libs\Response;
use App\Libs\Stream;
use App\Libs\TestCase;
use RuntimeException;
use Tests\Support\RequestResponseTrait;

class ParseJsonBodyMiddlewareTest extends TestCase
{
    use RequestResponseTrait;

    public function test_exceptions()
    {
        $this->checkException(
            closure: fn() => new ParseJsonBodyMiddleware()->process(
                request: $this->getRequest(method: 'NOT_OK')->withBody(
                    Stream::create(json_encode(['key' => 'test']))
                )->withHeader('Content-Type', 'application/json'),
                handler: $this->getHandler(new Response(Status::OK))
            ),
            reason: 'Should throw an exception when the method is not allowed',
            exception: RuntimeException::class,
            exceptionCode: Status::METHOD_NOT_ALLOWED->value
        );

        $this->checkException(
            closure: fn() => new ParseJsonBodyMiddleware()->process(
                request: $this->getRequest(Method::POST)->withBody(
                    Stream::create(json_encode(['key' => 'test']) . 'invalid json')
                )->withHeader('Content-Type', 'application/json'),
                handler: $this->getHandler()
            ),
            reason: 'Should throw an exception when the body is not a valid JSON',
            exception: RuntimeException::class,
            exceptionCode: Status::BAD_REQUEST->value
        );
    }

    public function test_empty_parsed_body()
    {
        $mutatedRequest = null;

        new ParseJsonBodyMiddleware()->process(
            request: $this->getRequest(Method::GET)->withBody(
                Stream::create(json_encode(['key' => 'test']))
            )->withHeader('Content-Type', 'application/json'),
            handler: $this->getHandler(function ($request) use (&$mutatedRequest) {
                $mutatedRequest = $request;
                return new Response(Status::OK);
            })
        );

        $this->assertCount(0, $mutatedRequest->getParsedBody(), 'Parsed body should be empty.');
        $this->assertSame([], $mutatedRequest->getParsedBody(), 'Parsed body should be null.');

        $mutatedRequest = null;

        new ParseJsonBodyMiddleware()->process(
            request: $this->getRequest(Method::POST)->withBody(
                Stream::create('')
            )->withHeader('Content-Type', 'application/json'),
            handler: $this->getHandler(function ($request) use (&$mutatedRequest) {
                $mutatedRequest = $request;
                return new Response(Status::OK);
            })
        );

        $this->assertCount(0, $mutatedRequest->getParsedBody(), 'Parsed body should be empty.');
        $this->assertSame([], $mutatedRequest->getParsedBody(), 'Parsed body should be null.');

        new ParseJsonBodyMiddleware()->process(
            request: $this->getRequest(Method::POST)->withBody(
                Stream::create(json_encode(['key' => 'test']))
            ),
            handler: $this->getHandler(function ($request) use (&$mutatedRequest) {
                $mutatedRequest = $request;
                return new Response(Status::OK);
            })
        );

        $this->assertCount(0, $mutatedRequest->getParsedBody(), 'Parsed body should have one item.');
        $this->assertSame([],
            $mutatedRequest->getParsedBody(),
            'Parsed body should be the same as the request body.'
        );
    }

    public function test_correct_mutation()
    {
        new ParseJsonBodyMiddleware()->process(
            request: $this->getRequest(Method::POST)->withBody(
                Stream::create(json_encode(['key' => 'test']))
            )->withHeader('Content-Type', 'application/json'),
            handler: $this->getHandler(function ($request) use (&$mutatedRequest) {
                $mutatedRequest = $request;
                return new Response(Status::OK);
            })
        );

        $this->assertCount(1, $mutatedRequest->getParsedBody(), 'Parsed body should have one item.');
        $this->assertSame(['key' => 'test'],
            $mutatedRequest->getParsedBody(),
            'Parsed body should be the same as the request body.'
        );
    }
}
