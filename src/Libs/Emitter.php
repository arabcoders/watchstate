<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Exceptions\EmitterException;
use Psr\Http\Message\ResponseInterface as IResponse;

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
        $this->assertNoPreviousOutput();

        $this->emitHeaders($response);

        // -- should be called after `emitHeaders()` in order to prevent PHP from changing the status code.
        $this->emitStatusLine($response);

        $this->emitBody($response);
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

        if (headers_sent($filename, $line)) {
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
    private function emitStatusLine(IResponse $response): void
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();

        $this->header(r('HTTP/{version} {status}{phrase}', [
            'version' => $response->getProtocolVersion(),
            'status' => $statusCode,
            'phrase' => !empty($reasonPhrase) ? ' ' . $reasonPhrase : ''
        ]), true, $statusCode);
    }

    /**
     * Emit response headers.
     *
     * Loops through each header, emitting each; if the header value
     * is an array with multiple values, ensures that each is sent
     * in such a way as to create aggregate headers (instead of replace
     * the previous).
     */
    private function emitHeaders(IResponse $response): void
    {
        $statusCode = $response->getStatusCode();

        foreach ($response->getHeaders() as $header => $values) {
            assert(is_string($header));
            $name = ucwords($header, '-');
            $first = $name !== 'Set-Cookie';
            foreach ($values as $value) {
                $this->header(r('{header}: {value}', [
                    'header' => $name,
                    'value' => $value,
                ]), $first, $statusCode);
                $first = false;
            }
        }
    }

    /**
     * Emit the message body.
     */
    private function emitBody(IResponse $response): void
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        if (!$body->isReadable()) {
            echo $body;
            return;
        }

        while (!$body->eof()) {
            echo $body->read($this->maxBufferLength);
            flush();
            if (CONNECTION_NORMAL !== connection_status()) {
                break;
            }
        }
    }

    private function header(string $headerName, bool $replace, int $statusCode): void
    {
        header($headerName, $replace, $statusCode);
    }
}
