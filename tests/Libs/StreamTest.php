<?php
/** @noinspection PhpVoidFunctionResultUsedInspection */

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Stream;
use App\Libs\TestCase;

class StreamTest extends TestCase
{
    private function getStream(mixed $stream = null, string $mode = 'w'): Stream
    {
        return new Stream($stream ?? 'php://memory', $mode);
    }

    public function test_constructor_exceptions()
    {
        $this->checkException(
            closure: fn() => $this->getStream('invalid://stream'),
            reason: 'Constructing a stream with an invalid stream reference should throw a RuntimeException',
            exception: RuntimeException::class,
        );

        $this->checkException(
            closure: fn() => Stream::make('invalid://stream'),
            reason: 'Constructing a stream with an invalid stream reference should throw a RuntimeException',
            exception: RuntimeException::class,
        );

        $this->checkException(
            closure: fn() => Stream::make(curl_init()),
            reason: 'Constructing a stream with an invalid resource type should throw an InvalidArgumentException',
            exception: InvalidArgumentException::class,
        );

        $this->checkException(
            closure: fn() => Stream::create(curl_init()),
            reason: 'Constructing a stream with an invalid resource type should throw an InvalidArgumentException',
            exception: InvalidArgumentException::class,
        );
    }

    public function test_tell_exceptions()
    {
        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $f->detach();
                return $f->tell();
            },
            reason: 'Detaching the stream and calling tell should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'No resource available',
        );

        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $body = $f->detach();
                $str = Stream::create($body);
                if (is_resource($body)) {
                    fclose($body);
                }
                return $str->tell();
            },
            reason: 'Closing stream from outside the stream class itself, and calling tell should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'cannot tell position',
        );
    }

    public function test_read_exceptions()
    {
        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $f->detach();
                return $f->read(1);
            },
            reason: 'Detaching the stream and calling read should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'No resource available',
        );

        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $body = $f->detach();
                $str = $this->getStream($body);
                if (is_resource($body)) {
                    fclose($body);
                }
                return $str->read(1);
            },
            reason: 'Closing stream from outside the stream class itself, and calling read should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'Stream is not readable',
        );
    }

    public function test_seek_exceptions()
    {
        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $f->detach();
                return $f->seek(0);
            },
            reason: 'Detaching the stream and calling seek should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'No resource available',
        );

        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $body = $f->detach();
                $str = $this->getStream($body);
                if (is_resource($body)) {
                    fclose($body);
                }
                return $str->seek(0);
            },
            reason: 'Closing stream from outside the stream class itself, and calling seek should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'Stream is not seekable',
        );
    }

    public function test_write_exceptions()
    {
        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $f->detach();
                return $f->write('test');
            },
            reason: 'Detaching the stream and calling write should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'No resource available',
        );

        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $body = $f->detach();
                $str = $this->getStream($body);
                if (is_resource($body)) {
                    fclose($body);
                }
                return $str->write('test');
            },
            reason: 'Closing stream from outside the stream class itself, and calling write should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'Stream is not writable',
        );
    }

    public function test_getContents_exceptions()
    {
        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $f->detach();
                return $f->getContents();
            },
            reason: 'Detaching the stream and calling getContents should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'Stream is not readable',
        );

        $this->checkException(
            closure: function () {
                $f = $this->getStream();
                $body = $f->detach();
                $str = $this->getStream($body);
                if (is_resource($body)) {
                    fclose($body);
                }
                return $str->getContents();
            },
            reason: 'Closing stream from outside the stream class itself, and calling getContents should throw a RuntimeException',
            exception: RuntimeException::class,
            exceptionMessage: 'Stream is not readable',
        );
    }

    public function test_typical_read()
    {
        $f = Stream::create('test');
        $f = Stream::create($f->detach());
        $text = '';
        do {
            $text .= $f->read(1);
        } while ($f->tell() !== ($f->getSize() - 2));

        while (!$f->eof()) {
            $text .= $f->read(1);
        }

        $this->assertSame('test', $text, 'Reading from the stream should return the written text');
        $this->assertSame('test', (string)$f, 'Reading from the stream should return the written text');
        $this->assertSame(
            '',
            $f->getContents(),
            'If the stream is read to the end, getContents should return an empty string'
        );

        if ($f->isSeekable()) {
            $f->rewind();
        }
        $this->assertSame(
            'test',
            $f->getContents(),
            'After rewinding the stream, getContents should return the full text'
        );
        $this->assertSame(
            4,
            $f->getSize(),
            'The size of the stream should be the length of the written text'
        );
        $this->assertTrue($f->isSeekable(), 'The stream should be seekable');
        $this->assertTrue($f->isReadable(), 'The stream should be readable');
        $this->assertTrue($f->isWritable(), 'The stream should not be writable');
        $f->close();
        $this->assertFalse($f->isSeekable(), 'The stream should not be seekable after detaching');
        $this->assertFalse($f->isReadable(), 'The stream should not be readable after detaching');
        $this->assertFalse($f->isWritable(), 'The stream should not be writable after detaching');
    }

    public function test_typical_write()
    {
        $f = $this->getStream();
        $f->write('test');
        $this->assertSame('test', (string)$f, 'Writing to the stream should write the text');
        $this->assertSame(4, $f->getSize(), 'The size of the stream should be the length of the written text');
        $this->assertTrue($f->isSeekable(), 'The stream should be seekable');
        $this->assertTrue($f->isReadable(), 'The stream should not be readable');
        $this->assertTrue($f->isWritable(), 'The stream should be writable');
        $f->close();
        $this->assertFalse($f->isSeekable(), 'The stream should not be seekable after detaching');
        $this->assertFalse($f->isReadable(), 'The stream should not be readable after detaching');
        $this->assertFalse($f->isWritable(), 'The stream should not be writable after detaching');
    }
}
