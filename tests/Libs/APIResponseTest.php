<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\APIResponse;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use App\Libs\TestCase;

class APIResponseTest extends TestCase
{
    public function test_response()
    {
        $body = [
            'id' => 1,
            'name' => 'test',
        ];

        $json = json_encode($body);

        $response = new APIResponse(Status::OK, headers: [
            'Content-Length' => strlen($json),
            'Content-Type' => 'application/json',
        ], body: $body, stream: Stream::create($json));

        $this->assertEquals(Status::OK, $response->status, 'Status is not OK');
        $this->assertEquals($body, $response->body, 'Body is not equal');
        $this->assertEquals($json, $response->stream->getContents(), 'Stream is not equal');
        $this->assertEquals('application/json', ag($response->headers, 'Content-Type'), 'Content-Type is not equal');
        $this->assertTrue($response->hasStream(), 'Stream is not available');
        $this->assertTrue($response->hasBody(), 'Body is not available');
        $this->assertTrue($response->hasHeaders(), 'Headers are not available');
        $this->assertEquals(strlen($json), $response->getHeader('Content-Length'), 'Content-Length is not equal');
        $this->assertSame(1, $response->getParam('id'), 'Param id is not equal');
    }
}
