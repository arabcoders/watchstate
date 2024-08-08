<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Exceptions\EmitterException;
use Psr\Http\Message\ResponseInterface as iResponse;

/**
 * @psalm-type ParsedRangeType = array{0:string,1:int,2:int,3:int|'*'}
 */
final readonly class Emitter
{
    /**
     * @param int $maxBufferLength int Maximum output buffering size for each iteration.
     */
    public function __construct(protected int $maxBufferLength = 8192)
    {
    }

    /**
     * Emit a response.
     *
     * @param IResponse $response The response to emit.
     */
    public function __invoke(IResponse $response): void
    {
        $this->emit($response);
    }

    /**
     * Emits a response for a PHP SAPI environment.
     *
     * Emits the status line and headers via the header() function, and the
     * body content via the output buffer.
     *
     * @param iResponse $response
     * @return bool
     */
    public function emit(iResponse $response): bool
    {
        $this->assertNoPreviousOutput();

        $flushAll = false;
        $maxBufferLength = $this->maxBufferLength;

        if ($response->hasHeader('X-Emitter-Flush')) {
            $flushAll = true;
            $response = $response->withoutHeader('X-Emitter-Flush');
        }

        if ($response->hasHeader('X-Emitter-Max-Buffer-Length')) {
            $maxBufferLength = (int)$response->getHeaderLine('X-Emitter-Max-Buffer-Length');
            $response = $response->withoutHeader('X-Emitter-Max-Buffer-Length');
        }

        $this->emitHeaders($response);
        $this->emitStatusLine($response);

        flush();

        $range = $this->parseContentRange($response->getHeaderLine('Content-Range'));

        if (null === $range || 'bytes' !== $range[0]) {
            $this->emitBody($response, $maxBufferLength, $flushAll);
            return true;
        }

        $this->emitBodyRange($range, $response, $maxBufferLength, $flushAll);
        return true;
    }

    /**
     * Emit the message body.
     *
     * @param iResponse $response The response to emit.
     * @param int $maxBuffer Maximum output buffering size for each iteration.
     * @param bool $flushAll Whether to dump the entire body.
     */
    private function emitBody(iResponse $response, int $maxBuffer, bool $flushAll = false): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (!$body->isReadable() || true === $flushAll) {
            echo $body;
            flush();
            return;
        }

        while (!$body->eof()) {
            echo $body->read($maxBuffer);
            flush();
            if (CONNECTION_NORMAL !== connection_status()) {
                break;
            }
        }
    }

    /**
     * Emit a range of the message body.
     *
     * @psalm-param ParsedRangeType $range
     * @param iResponse $response The response to emit.
     * @param int $maxBuffer Maximum output buffering size for each iteration.
     * @param bool $flushAll Whether to dump the entire body.
     */
    private function emitBodyRange(array $range, iResponse $response, int $maxBuffer, bool $flushAll = false): void
    {
        [, $first, $last] = $range;

        $body = $response->getBody();

        $length = $last - $first + 1;

        if ($body->isSeekable()) {
            $body->seek($first);
            $first = 0;
        }

        if (!$body->isReadable() || true === $flushAll) {
            echo substr($body->getContents(), $first, $length);
            flush();
            return;
        }

        $remaining = $length;

        while ($remaining >= $maxBuffer && !$body->eof()) {
            $contents = $body->read($maxBuffer);
            $remaining -= strlen($contents);

            echo $contents;
            flush();

            if (CONNECTION_NORMAL !== connection_status()) {
                break;
            }
        }

        if ($remaining > 0 && !$body->eof()) {
            echo $body->read($remaining);
            flush();
        }
    }

    /**
     * Parse content-range header
     * http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.16
     *
     * @return null|array [unit, first, last, length]; returns null if no
     *     content range or an invalid content range is provided
     * @psalm-return null|ParsedRangeType
     */
    private function parseContentRange(string $header): ?array
    {
        if (!preg_match('/(?P<unit>\w+)\s+(?P<first>\d+)-(?P<last>\d+)\/(?P<length>\d+|\*)/', $header, $matches)) {
            return null;
        }

        return [
            $matches['unit'],
            (int)$matches['first'],
            (int)$matches['last'],
            $matches['length'] === '*' ? '*' : (int)$matches['length'],
        ];
    }

    /**
     * Checks to see if content has previously been sent.
     *
     * If either headers have been sent or the output buffer contains content,
     * raises an exception.
     *
     * @throws EmitterException If headers have already been sent.
     * @throws EmitterException If output is present in the output buffer.
     */
    private function assertNoPreviousOutput(): void
    {
        $filename = null;
        $line = null;
        if ($this->headersSent($filename, $line)) {
            assert(is_string($filename) && is_int($line));
            throw EmitterException::forHeadersSent($filename, $line);
        }

        if (ob_get_level() > 0 && ob_get_length() > 0) {
            throw EmitterException::forOutputSent();
        }
    }

    /**
     * Emit the status line.
     *
     * Emits the status line using the protocol version and status code from
     * the response; if a reason phrase is available, it, too, is emitted.
     *
     * It is important to mention that this method should be called after
     * `emitHeaders()` in order to prevent PHP from changing the status code of
     * the emitted response.
     */
    private function emitStatusLine(iResponse $response): void
    {
        $reasonPhrase = $response->getReasonPhrase();
        $statusCode = $response->getStatusCode();

        $this->header(
            sprintf(
                'HTTP/%s %d%s',
                $response->getProtocolVersion(),
                $statusCode,
                $reasonPhrase ? ' ' . $reasonPhrase : ''
            ),
            true,
            $statusCode
        );
    }

    /**
     * Emit response headers.
     *
     * Loops through each header, emitting each; if the header value
     * is an array with multiple values, ensures that each is sent
     * in such a way as to create aggregate headers (instead of replace
     * the previous).
     */
    private function emitHeaders(iResponse $response): void
    {
        $statusCode = $response->getStatusCode();

        foreach ($response->getHeaders() as $header => $values) {
            assert(is_string($header));
            $name = $this->filterHeader($header);
            if (true === str_starts_with($name, 'X-Emitter')) {
                continue;
            }
            $first = $name !== 'Set-Cookie';
            foreach ($values as $value) {
                $this->header("{$name}: {$value}", $first, $statusCode);
                $first = false;
            }
        }
    }

    /**
     * Filter a header name to word case
     */
    private function filterHeader(string $header): string
    {
        return ucwords($header, '-');
    }

    private function headersSent(?string &$filename = null, ?int &$line = null): bool
    {
        return headers_sent($filename, $line);
    }

    private function header(string $headerName, bool $replace, int $statusCode): void
    {
        header($headerName, $replace, $statusCode);
    }
}
