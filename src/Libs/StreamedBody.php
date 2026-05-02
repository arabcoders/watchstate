<?php

declare(strict_types=1);

namespace App\Libs;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class StreamedBody implements StreamInterface
{
    private mixed $func;
    private bool $executed = false;
    private string $buffer = '';
    private int $position = 0;

    public function __construct(
        callable $func,
        private bool $isReadable = true,
    ) {
        $this->func = $func;
    }

    public static function create(
        callable $func,
        bool $isReadable = true,
    ): StreamInterface {
        return new self($func, isReadable: $isReadable);
    }

    public function __destruct() {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void {}

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
        return $this->position;
    }

    public function eof(): bool
    {
        return true === $this->executed && $this->position >= strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = \SEEK_SET): void {}

    public function rewind(): void {}

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
        return $this->isReadable;
    }

    public function read($length): string
    {
        if (!is_int($length) || 1 > $length) {
            return '';
        }

        $this->execute();

        if (true === $this->eof()) {
            return '';
        }

        $chunk = substr($this->buffer, $this->position, $length);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function getContents(): string
    {
        $this->execute();

        if (true === $this->eof()) {
            return '';
        }

        $chunk = substr($this->buffer, $this->position);
        $this->position = strlen($this->buffer);
        return $chunk;
    }

    private function execute(): void
    {
        if (true === $this->executed) {
            return;
        }

        $result = ($this->func)();
        $this->buffer = is_string($result) ? $result : '';
        $this->executed = true;
    }

    public function getMetadata($key = null): null
    {
        return null;
    }
}
