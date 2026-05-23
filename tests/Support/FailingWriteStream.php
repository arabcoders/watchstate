<?php

declare(strict_types=1);

namespace Tests\Support;

use Nyholm\Psr7\Stream as Psr7Stream;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class FailingWriteStream implements StreamInterface
{
    private StreamInterface $stream;

    public function __construct(private string $message = 'write failed', ?StreamInterface $stream = null)
    {
        $this->stream = $stream ?? Psr7Stream::create('');
    }

    public function __toString(): string
    {
        return (string) $this->stream;
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->stream->rewind();
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function write(string $string): int
    {
        throw new RuntimeException($this->message);
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
    }
}
