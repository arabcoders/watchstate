<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Exceptions\InvalidArgumentException;
use App\Libs\Exceptions\RuntimeException;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stringable;
use Throwable;

/**
 * Class Stream
 *
 * The Stream class represents a stream resource or file path.
 *
 * @implements StreamInterface
 */
final class Stream implements StreamInterface, Stringable, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var array<string> A list of allowed stream resource types that are allowed to instantiate a stream
     */
    private const array ALLOWED_RESOURCE_TYPES = ['gd', 'stream'];

    /**
     * @var resource|null The underlying stream resource.
     */
    protected $resource;

    /**
     * @param string|resource $stream The stream resource or file path.
     * @param string $mode The stream mode. Default is 'r'.
     *
     * @throws RuntimeException If an invalid stream reference is provided.
     * @throws InvalidArgumentException If the stream type is unexpected.
     */
    public function __construct(mixed $stream, string $mode = 'r')
    {
        $error = null;
        $resource = $stream;

        if (is_string($stream)) {
            set_error_handler(function ($severity, $message, $file, $line) use (&$error) {
                if ($severity !== E_WARNING) {
                    return;
                }

                $error = r("Stream: Failed to open stream. '{message}' at '{file}:{line}'", [
                    'message' => trim($message),
                    'file' => $file,
                    'line' => $line
                ]);
            });
            $resource = fopen($stream, $mode);
            restore_error_handler();
        }

        if (null !== $error) {
            throw new RuntimeException($error);
        }

        if (!self::isValidResourceType($resource)) {
            throw new InvalidArgumentException(
                r(
                    text: 'Stream: Unexpected [{type}] type was given. Stream must be a file path or stream resource.',
                    context: [
                        'type' => gettype($resource),
                    ]
                )
            );
        }

        $this->resource = $resource;
    }

    /**
     * Create a new Stream instance.
     *
     * @param string|resource $stream The stream resource or file path.
     * @param string $mode The stream mode. Default is 'r'.
     *
     * @return StreamInterface The new Stream instance.
     *
     * @throws RuntimeException If an invalid stream reference is provided.
     * @throws InvalidArgumentException If the stream type is unexpected.
     */
    public static function make(mixed $stream, string $mode = 'r'): StreamInterface
    {
        return new self($stream, $mode);
    }

    /**
     * Create in-memory stream with given contents.
     *
     * @param string|resource|StreamInterface $body The stream contents.
     *
     * @throws InvalidArgumentException If the $body arg is not a string, resource or StreamInterface.
     */
    public static function create(mixed $body = ''): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            return $body;
        }

        if (is_string($body)) {
            $resource = \fopen('php://memory', 'r+');
            fwrite($resource, $body);
            fseek($resource, 0);
            return new self($resource);
        }

        if (!self::isValidResourceType($body)) {
            throw new InvalidArgumentException(
                'First argument to Stream::create() must be a string, resource or StreamInterface'
            );
        }

        return new self($body);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        if (!$this->isReadable()) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if (!$this->resource) {
            return;
        }

        $resource = $this->detach();
        fclose($resource);
    }

    /**
     * {@inheritdoc}
     */
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): ?int
    {
        if (null === $this->resource) {
            return null;
        }

        $stats = @fstat($this->resource);
        if (false !== $stats) {
            return $stats['size'];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function tell(): int
    {
        if (!is_resource($this->resource)) {
            throw new RuntimeException('Stream: No resource available, cannot tell position.');
        }

        $result = ftell($this->resource);

        if (false === $result) {
            throw new RuntimeException('Stream: Error occurred during tell operation.', 202);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        if (!$this->resource) {
            return true;
        }

        return feof($this->resource);
    }

    /**
     * {@inheritdoc}
     */
    public function isSeekable(): bool
    {
        if (!$this->resource) {
            return false;
        }

        try {
            return $this->getMetadata('seekable');
        } catch (Throwable $e) {
            $this->logger?->error('Stream: {class}: {message}', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function seek($offset, $whence = \SEEK_SET): void
    {
        if (!$this->resource) {
            throw new RuntimeException('Stream: No resource available; cannot seek position');
        }

        if (!$this->isSeekable()) {
            throw new RuntimeException('Stream: Stream is not seekable');
        }

        $result = fseek($this->resource, $offset, $whence);

        if (0 !== $result) {
            throw new RuntimeException('Stream: Error seeking within stream');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(): bool
    {
        if (!$this->resource) {
            return false;
        }

        try {
            $mode = $this->getMetadata('mode');
            return (str_contains($mode, 'x') || str_contains($mode, 'w') ||
                str_contains($mode, 'c') || str_contains($mode, 'a') || str_contains($mode, '+'));
        } catch (Throwable $e) {
            $this->logger?->error('Stream: {class}: {message}', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $string): int
    {
        if (!$this->resource) {
            throw new RuntimeException('Stream: No resource available; cannot write.');
        }

        if (!$this->isWritable()) {
            throw new RuntimeException('Stream: Stream is not writable.');
        }

        $result = fwrite($this->resource, $string);

        if (false === $result) {
            throw new RuntimeException('Stream: Error writing to stream.');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        if (!$this->resource) {
            return false;
        }

        try {
            $mode = $this->getMetadata('mode');
            return str_contains($mode, 'r') || str_contains($mode, '+');
        } catch (Throwable $e) {
            $this->logger?->error('Stream: {class}: {message}', [
                'class' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length): string
    {
        if (!$this->resource) {
            throw new RuntimeException('Stream: No resource available; cannot read');
        }

        if (!$this->isReadable()) {
            throw new RuntimeException('Stream: Stream is not readable');
        }

        $result = fread($this->resource, $length);

        if (false === $result) {
            throw new RuntimeException('Stream: Error reading stream');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream: Stream is not readable.');
        }

        $result = stream_get_contents($this->resource);

        if (false === $result) {
            throw new RuntimeException('Stream: Error reading stream');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(string|null $key = null)
    {
        $metadata = stream_get_meta_data($this->resource);

        return null !== $key ? ($metadata[$key] ?? null) : $metadata;
    }

    /**
     * Determine if a resource is one of the resource types allowed to instantiate a Stream
     *
     * @param resource $resource Stream resource.
     *
     * @return bool True if the resource is one of the allowed types, false otherwise.
     */
    private static function isValidResourceType($resource): bool
    {
        if (is_resource($resource)) {
            return in_array(get_resource_type($resource), self::ALLOWED_RESOURCE_TYPES, true);
        }

        if (extension_loaded('gd') && true === ($resource instanceof \GdImage)) {
            return true;
        }

        return false;
    }
}
