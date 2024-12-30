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
    private function getStream(Closure|null $fn = null, bool $isReadable = true): StreamedBody
    {
        return new StreamedBody($fn ?? fn() => 'test', isReadable: $isReadable);
    }

    public function test_expectations()
    {
        $fn = fn() => 'test';
        $this->assertSame(
            'test',
            StreamedBody::create($fn)->getContents(),
            'getContents(): Must return the same value as the callback'
        );

        $this->assertSame('test', $this->getStream()->__toString(), 'Must implement __toString');
        $this->assertSame('test', (string)$this->getStream(), 'Must implement __toString');
        $this->assertNull(
            $this->getStream()->getMetadata('key'),
            "getMetadata(): Must return null as closure doesn't have metadata"
        );
        $this->assertNull(
            $this->getStream()->getSize(),
            'getSize(): Must return null as closure does not have a size'
        );

        $this->assertSame(
            'test',
            $this->getStream()->read(1),
            'read(): Must return the same value as the callback regardless of the read length'
        );

        $this->assertSame(0, $this->getStream()->tell(), 'tell(): Must return 0 as closure does not have a position');
        $this->assertFalse($this->getStream()->eof(), 'eof(): Must return false as closure does not have an end');
        $this->assertTrue($this->getStream()->isReadable(), 'isReadable(): Must return true as closure is readable');
        $this->assertFalse(
            $this->getStream()->isWritable(),
            'isWritable(): Must return false as closure is not writable'
        );
        $this->assertFalse(
            $this->getStream()->isSeekable(),
            'isSeekable(): Must return false as closure is not seekable'
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
            'isReadable(): Must return false as closure is not readable'
        );
    }
}
