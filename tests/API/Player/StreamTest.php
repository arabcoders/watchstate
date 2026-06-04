<?php

declare(strict_types=1);

namespace Tests\API\Player;

use App\API\Player\Stream;
use App\API\System\Sign;
use App\Libs\Emitter;
use App\Libs\Enums\Http\Status;
use App\Libs\TestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class StreamTest extends TestCase
{
    public function test_stream_range(): void
    {
        $this->initTempDir();
        $path = self::$tmpPath . '/sample.mp4';
        file_put_contents($path, 'abcdef');

        $cache = new Psr16Cache(new ArrayAdapter());
        $token = Sign::sign([
            'id' => 1,
            'path' => $path,
            'time' => 'PT24H',
            'config' => [],
            'version' => get_app_version(),
        ], cache: $cache);

        $request = new ServerRequest('GET', new Uri('http://localhost/v1/api/player/stream/' . $token))
            ->withHeader('Range', 'bytes=1-3');

        $response = (new Stream($cache))($request, $token);
        $out = '';

        new Emitter()
            ->withHeaderFunc(static fn() => null)
            ->withHeadersSentFunc(static fn() => false)
            ->withBodyFunc(static function (string $data) use (&$out): void {
                $out .= $data;
            })
            ->emit($response);

        $this->assertSame(Status::PARTIAL_CONTENT, Status::from($response->getStatusCode()));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertSame('bytes 1-3/6', $response->getHeaderLine('Content-Range'));
        $this->assertSame('3', $response->getHeaderLine('Content-Length'));
        $this->assertSame('bcd', $out);
    }

    public function test_stream_bad_range(): void
    {
        $this->initTempDir();
        $path = self::$tmpPath . '/sample.mp4';
        file_put_contents($path, 'abcdef');

        $cache = new Psr16Cache(new ArrayAdapter());
        $token = Sign::sign([
            'id' => 1,
            'path' => $path,
            'time' => 'PT24H',
            'config' => [],
            'version' => get_app_version(),
        ], cache: $cache);

        $request = new ServerRequest('GET', new Uri('http://localhost/v1/api/player/stream/' . $token))
            ->withHeader('Range', 'bytes=10-20');

        $response = (new Stream($cache))($request, $token);

        $this->assertSame(Status::REQUESTED_RANGE_NOT_SATISFIABLE, Status::from($response->getStatusCode()));
        $this->assertSame('bytes */6', $response->getHeaderLine('Content-Range'));
    }
}
