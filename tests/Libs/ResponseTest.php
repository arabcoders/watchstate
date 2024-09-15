<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Enums\Http\Status;
use App\Libs\Response;
use App\Libs\Stream;
use App\Libs\TestCase;

class ResponseTest extends TestCase
{
    public function test_protocol()
    {
        $response = new Response(Status::OK, version: '1.0');
        $this->assertSame(
            '1.0',
            $response->getProtocolVersion(),
            'Should return the same protocol version from constructor.'
        );

        $response = $response->withProtocolVersion('1.1');
        $this->assertSame(
            '1.1',
            $response->getProtocolVersion(),
            'Should return the same protocol version from response.'
        );

        $this->assertSame(
            spl_object_id($response),
            spl_object_id($response->withProtocolVersion('1.1')),
            'withProtocolVersion: should return same object when same version is set.'
        );
    }

    public function test_status()
    {
        $response = new Response(Status::ACCEPTED, reason: 'OK');
        $this->assertSame(
            Status::ACCEPTED,
            Status::tryfrom($response->getStatusCode()),
            'Validate same status code are returned from response.'
        );

        $this->assertSame(
            'OK',
            $response->getReasonPhrase(),
            'Validate same reason phrase are returned from response.'
        );

        $response = $response->withStatus(Status::BAD_REQUEST);
        $this->assertSame(
            Status::BAD_REQUEST,
            Status::tryfrom($response->getStatusCode()),
            'Validate same status code are returned from response.'
        );

        $this->checkException(
            closure: fn() => new Response(999),
            reason: 'Exception is thrown when invalid status code is passed',
            exception: \InvalidArgumentException::class,
        );

        $this->checkException(
            closure: fn() => $response->withStatus(999),
            reason: 'Exception is thrown when invalid status code is passed',
            exception: \InvalidArgumentException::class,
        );

        $this->assertSame(
            spl_object_id($response),
            spl_object_id($response->withStatus(Status::BAD_REQUEST, '')),
            'withStatus: should return same object when same status is set.'
        );

        $this->assertNotSame(
            spl_object_id($response),
            spl_object_id($response->withStatus(Status::BAD_REQUEST, 'OK')),
            "withStatus: shouldn't return same object when reason is different but status code the same."
        );
    }

    public function test_headers()
    {
        $response = new Response(Status::OK, headers: [
            123 => 'a_numeric_header_key',
            'X-From-Construct' => 'has_header',
        ]);

        $this->assertSame(
            'has_header',
            $response->getHeaderLine('X-From-Construct'),
            'Validate constructor headers are returned from response.'
        );

        $this->assertSame(
            'a_numeric_header_key',
            $response->getHeaderLine('123'),
            'Validate constructor headers are returned from response.'
        );

        $response = $response->withHeader('Custom-Headers', 'Custom-Value');
        $this->assertSame(
            'Custom-Value',
            $response->getHeaderLine('Custom-Headers'),
            'Validate same headers are returned from response.'
        );

        $response = $response->withHeader('X-From-With-Header', ['Custom-Value-1', 'Custom-Value-2']);
        $this->assertSame(
            'Custom-Value-1, Custom-Value-2',
            $response->getHeaderLine('X-From-With-Header'),
            'Validate same headers are returned from response.'
        );

        $response = $response->withAddedHeader('Custom-Headers', 'Custom-Value-2');
        $this->assertSame(
            'Custom-Value, Custom-Value-2',
            $response->getHeaderLine('Custom-Headers'),
            'Validate same headers are returned from response.'
        );

        $this->assertSame(
            [0 => 'Custom-Value', 1 => 'Custom-Value-2',],
            ag($response->getHeaders(), 'Custom-Headers', []),
            'getHeaders: Validate same headers are returned from response.'
        );

        $this->assertSame(
            spl_object_id($response),
            spl_object_id($response->withoutHeader('X-Not-set')),
            'withoutHeader: should return same object when header is not set.'
        );

        $this->assertSame(
            'test',
            $response->withHeader('Custom-Headers', 'test')->getHeaderLine('Custom-Headers'),
            'Calling withHeader should replace the existing header.'
        );

        $response = $response->withoutHeader('Custom-Headers');
        $this->assertSame(
            '',
            $response->getHeaderLine('Custom-Headers'),
            'Validate same headers are returned from response.'
        );


        $this->checkException(
            closure: fn() => $response->withHeader('X-عربي', 'test'),
            reason: 'Invalid header name',
            exception: \InvalidArgumentException::class,
            exceptionMessage: 'RFC 7230',
        );

        $this->checkException(
            closure: fn() => $response->withHeader('X-Value', "Hello\nWorld"),
            reason: 'Invalid header value according to RFC 7230',
            exception: \InvalidArgumentException::class,
            exceptionMessage: 'RFC 7230',
        );

        $this->checkException(
            closure: fn() => $response->withHeader('X-Value', []),
            reason: 'Invalid header value, empty value.',
            exception: \InvalidArgumentException::class,
            exceptionMessage: 'Header values must be a string',
        );
        $this->checkException(
            closure: fn() => $response->withHeader('X-Value', ["Hello\nWorld"]),
            reason: 'Invalid header value according to RFC 7230',
            exception: \InvalidArgumentException::class,
            exceptionMessage: 'RFC 7230',
        );

        $this->checkException(
            closure: fn() => $response->withAddedHeader('', ["Hello\nWorld"]),
            reason: 'Exception is thrown when header name is empty',
            exception: \InvalidArgumentException::class,
            exceptionMessage: 'RFC 7230',
        );
    }

    public function test_body()
    {
        $response = new Response(Status::OK);
        $this->assertSame(
            '',
            $response->getBody()->getContents(),
            'When no body is set, it should create Stream object with empty string.'
        );

        $response = new Response(Status::OK, body: 'Hello World');
        $this->assertSame(
            'Hello World',
            $response->getBody()->getContents(),
            'Validate same body are returned from response.'
        );

        $stream = Stream::create('Hello World');

        $response = $response->withBody($stream);
        $this->assertSame(
            'Hello World',
            $response->getBody()->getContents(),
            'Validate same body are returned from response.'
        );

        $this->assertSame(
            spl_object_id($response),
            spl_object_id($response->withBody($stream)),
            'withBody: should return same object when same body is set.'
        );
    }
}
