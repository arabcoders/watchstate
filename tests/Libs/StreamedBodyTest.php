<?php
/** @noinspection PhpVoidFunctionResultUsedInspection */

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\StreamedBody;
use App\Libs\TestCase;
use Closure;
use RuntimeException;

class StreamedBodyTest extends TestCase
{
    private function getStream(?Closure $fn = null, bool $isReadable = true): StreamedBody
    {
        return new StreamedBody($fn ?? fn() => 'test', isReadable: $isReadable);
    }

    public function test_expectations()
    {
        $fn = fn() => 'test';
        $stream = StreamedBody::create($fn);

        $this->assertSame(
            'test',
            $stream->getContents(),
            'getContents(): Must return the same value as the callback',
        );
        $this->assertSame('', $stream->getContents());

        $this->assertSame('test', $this->getStream()->__toString(), 'Must implement __toString');
        $this->assertSame('test', (string) $this->getStream(), 'Must implement __toString');
        $this->assertNull(
            $this->getStream()->getMetadata('key'),
            "getMetadata(): Must return null as closure doesn't have metadata",
        );
        $this->assertNull(
            $this->getStream()->getSize(),
            'getSize(): Must return null as closure does not have a size',
        );

        $this->assertSame(
            't',
            $this->getStream()->read(1),
            'read(): Must return only the requested buffered chunk length',
        );

        $stream = $this->getStream();
        $this->assertSame('t', $stream->read(1));
        $this->assertSame(1, $stream->tell(), 'tell(): Must advance as the buffered body is consumed');
        $this->assertFalse($stream->eof(), 'eof(): Must remain false until the buffered body is consumed');
        $this->assertSame('est', $stream->getContents());
        $this->assertTrue($stream->eof(), 'eof(): Must become true after the buffered body is consumed');

        $this->assertSame(0, $this->getStream()->tell(), 'tell(): Must return 0 before the stream is consumed');
        $this->assertFalse($this->getStream()->eof(), 'eof(): Must return false before the callback executes');
        $this->assertTrue($this->getStream()->isReadable(), 'isReadable(): Must return true as closure is readable');
        $this->assertFalse(
            $this->getStream()->isWritable(),
            'isWritable(): Must return false as closure is not writable',
        );
        $this->assertFalse(
            $this->getStream()->isSeekable(),
            'isSeekable(): Must return false as closure is not seekable',
        );

        $this->assertNull($this->getStream()->detach(), 'detach(): Must return null as closure is not detachable');
        $this->assertNull($this->getStream()->seek(0), 'seek(): Must return null as closure is not seekable');
        $this->assertNull($this->getStream()->rewind(), 'rewind(): Must return null as closure is not seekable');
        $this->assertNull($this->getStream()->close(), 'close(): Must return null as closure is not closeable');

        $this->checkException(
            closure: fn() => $this->getStream()->write('test'),
            reason: 'write(): Must throw an exception as closure is not writable',
            exception: RuntimeException::class,
        );

        $this->assertFalse(
            $this->getStream(isReadable: false)->isReadable(),
            'isReadable(): Must return false as closure is not readable',
        );
    }

    public function test_streamed_executes_once(): void
    {
        $calls = 0;
        $stream = new StreamedBody(function () use (&$calls): string {
            $calls++;
            return 'streamed';
        });

        $this->assertFalse($stream->eof());
        $this->assertSame('streamed', $stream->read(8192));
        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(8192));
        $this->assertSame('', $stream->getContents());
        $this->assertSame(1, $calls);
    }

    public function test_empty_callback_before_eof(): void
    {
        $calls = 0;
        $stream = new StreamedBody(function () use (&$calls): string {
            $calls++;
            return '';
        });

        $this->assertFalse($stream->eof());
        $this->assertSame('', $stream->read(8192));
        $this->assertTrue($stream->eof());
        $this->assertSame(1, $calls);
    }
}
