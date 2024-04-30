<?php

declare(strict_types=1);

namespace App\Libs;

use Closure;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use const SEEK_SET;

class StreamClosure implements StreamInterface
{
    private Closure $callback;

    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    public static function create(Closure $callback): StreamInterface
    {
        return new self($callback);
    }

    public function __destruct()
    {
    }

    public function __toString()
    {
        return $this->getContents();
    }

    public function close(): void
    {
    }

    public function detach(): null
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('Unable to write to a non-writable stream.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        return $this->getContents();
    }

    public function getContents(): string
    {
        $func = $this->callback;

        return (string)$func();
    }

    public function getMetadata($key = null): null
    {
        return null;
    }
}
