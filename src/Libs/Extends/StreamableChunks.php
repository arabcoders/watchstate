<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Generator;
use IteratorAggregate;
use Psr\Http\Message\StreamInterface as iStream;
use ReturnTypeWillChange;

/**
 * @implements IteratorAggregate<int, string>
 */
class StreamableChunks implements IteratorAggregate
{
    /**
     * @param iStream $stream The stream to be used.
     * @param int $chunkSize The chunk size to be used.
     */
    public function __construct(private iStream $stream, private int $chunkSize = 1024 * 8)
    {
    }

    /**
     * Get the iterator.
     *
     * @return Generator
     */
    #[ReturnTypeWillChange]
    public function getIterator(): Generator
    {
        while ('' !== ($chunk = $this->stream->read($this->chunkSize))) {
            yield $chunk;
        }
    }
}
