<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Emitter;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\EmitterException;
use App\Libs\Response;
use App\Libs\Stream;
use App\Libs\StreamedBody;
use App\Libs\TestCase;

class EmitterTest extends TestCase
{
    private array $headers = [];
    private string $body = '';

    private Emitter|null $emitter = null;

    protected function reset(): void
    {
        $this->headers = [];
        $this->body = '';
        $this->emitter = (new Emitter())
            ->withHeaderFunc(function ($header, $replace, $status) {
                $this->headers[] = [
                    'header' => $header,
                    'replace' => $replace,
                    'status' => $status
                ];
            })
            ->withHeadersSentFunc(fn() => false)
            ->withBodyFunc(fn(string $data) => $this->body .= $data)
            ->withMaxBufferLength(8192);
    }

    protected function setUp(): void
    {
        $this->reset();
        parent::setUp();
    }

    public function test_emitter_headers()
    {
        $response = new Response(Status::OK, headers: [
            'content-type' => 'text/plain',
            'X-TEST' => 'test',
        ], version: '2.0');

        $this->emitter->__invoke($response);

        $this->assertSame(
            'Content-Type: text/plain',
            $this->headers[0]['header'],
            'Content-Type header is not set correctly.'
        );

        $this->assertSame(
            'X-Test: test',
            $this->headers[1]['header'],
            'X-TEST header is not set correctly.'
        );

        $this->assertSame(
            'HTTP/2.0 200 OK',
            $this->headers[2]['header'],
            'Status line is not set correctly.'
        );
    }

    public function test_emitter_body()
    {
        $response = new Response(Status::OK, headers: [
            'content-type' => 'text/plain',
            'X-TEST' => 'test',
        ], body: Stream::create('test'), version: '2.0');

        $this->emitter->__invoke($response);
        $this->assertSame('test', $this->body, 'Body is not set correctly.');

        $this->reset();
        $this->emitter->__invoke($response->withHeader('Content-Range', 'bytes 0-1/4'));
        $this->assertSame('te', $this->body, 'Body is not set correctly.');

        $this->reset();
        $this->emitter->__invoke($response->withHeader('Content-Range', 'bytes 2-3/4'));
        $this->assertSame('st', $this->body, 'Body is not set correctly.');

        $this->reset();
        $this->emitter->__invoke($response->withHeader('Content-Range', 'bytes 2-3/4'));
        $this->assertSame('st', $this->body, 'Body is not set correctly.');

        $this->reset();
        $this->emitter->__invoke($response->withHeader('X-Emitter-Max-Buffer-Length', '1'));
        $this->assertSame('test', $this->body, 'Body is not set correctly.');

        $this->reset();
        $this->emitter->withMaxBufferLength(1)->__invoke($response->withHeader('Content-Range', 'bytes 0-3/4'));
        $this->assertSame('test', $this->body, 'Body is not set correctly.');
    }

    public function test_emitter_body_streamable()
    {
        $response = new Response(Status::OK, headers: [
            'X-Emitter-Flush' => '1',
        ], body: StreamedBody::create(fn() => 'test'));

        $this->emitter->__invoke($response);
        $this->assertSame('test', $this->body, 'Body is not set correctly.');

        $this->reset();
        $this->emitter->__invoke(
            $response->withoutHeader('X-Emitter-Flush')->withBody(
                StreamedBody::create(fn() => 'test', isReadable: false)
            )
        );
        $this->assertSame('test', $this->body, 'Body is not set correctly.');
    }

    public function test_fail_conditions()
    {
        $response = new Response(Status::OK, headers: [
            'content-type' => 'text/plain',
            'X-TEST' => 'test',
        ], body: Stream::create('test'), version: '2.0');


        $emitter = $this->emitter->withHeadersSentFunc(function (&$file, &$line): bool {
            $file = 'test';
            $line = 1;
            return true;
        });

        $this->checkException(
            closure: fn() => $emitter->__invoke($response),
            reason: 'Headers already sent.',
            exception: EmitterException::class,
            exceptionCode: EmitterException::HEADERS_SENT
        );

        ob_start();
        echo 'foo';

        $this->checkException(
            closure: fn() => $this->emitter->__invoke($response),
            reason: 'Headers already sent.',
            exception: EmitterException::class,
            exceptionCode: EmitterException::OUTPUT_SENT
        );

        ob_end_clean();
    }
}
