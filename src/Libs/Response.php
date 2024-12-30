<?php

declare(strict_types=1);

namespace App\Libs;

use App\libs\Enums\Http\Status;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\StreamInterface as iStream;

/**
 * A simple implementation of PSR-7 ResponseInterface.
 *
 * Based on Nyholm\Psr7 code. {@link https://github.com/Nyholm/psr7}
 */
class Response implements iResponse
{
    /**
     * Map of standard HTTP status code/reason phrases
     *
     * @var array
     *
     * @psalm-var array<positive-int, non-empty-string>
     */
    private const array PHRASES = [
        // INFORMATIONAL CODES
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        // SUCCESS CODES
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        // REDIRECTION CODES
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy', // Deprecated to 306 => '(Unused)'
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        // CLIENT ERROR
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        444 => 'Connection Closed Without Response',
        451 => 'Unavailable For Legal Reasons',
        // SERVER ERROR
        499 => 'Client Closed Request',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
        599 => 'Network Connect Timeout Error',
    ];

    private string $reasonPhrase;

    private int $statusCode;

    /**
     * List of all registered headers, as key => array of values.
     *
     * @var array<string,array<string>>
     */
    protected array $headers = [];

    /**
     * Map of normalized header name to original name used to register header.
     *
     * @var array<string,mixed>
     */
    protected array $headerNames = [];

    private string $protocol;

    private iStream|null $stream = null;

    /**
     * @param Status|int $status Status code for the response, if any.
     * @param array $headers Headers for the response, if any.
     * @param string|resource|iStream|null $body Stream identifier and/or actual stream resource
     */
    public function __construct(
        Status|int $status = Status::OK,
        array $headers = [],
        mixed $body = null,
        string $version = '1.1',
        string|null $reason = null
    ) {
        if (null !== $body && '' !== $body) {
            $this->stream = Stream::create($body);
        }

        $this->setHeaders($headers);

        $code = !is_int($status) ? $status->value : $status;
        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException(
                sprintf("Status code has to be an integer between 100 and 599. A status code of '%d' was given.", $code)
            );
        }

        $this->statusCode = $code;

        if (null === $reason && isset(self::PHRASES[$this->statusCode])) {
            $this->reasonPhrase = self::PHRASES[$this->statusCode];
        } else {
            $this->reasonPhrase = $reason ?? '';
        }

        $this->protocol = $version;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function withStatus(int|Status $code, string $reasonPhrase = ''): iResponse
    {
        if (!is_int($code)) {
            $code = $code->value;
        }

        if ($code === $this->statusCode && ('' === $reasonPhrase || $reasonPhrase === $this->reasonPhrase)) {
            return $this;
        }

        if ($code < 100 || $code > 599) {
            throw new InvalidArgumentException(
                sprintf("Status code has to be an integer between 100 and 599. A status code of '%d' was given.", $code)
            );
        }

        $new = clone $this;
        $new->statusCode = $code;

        if ('' === $reasonPhrase && isset(self::PHRASES[$new->statusCode])) {
            $reasonPhrase = self::PHRASES[$new->statusCode];
        }

        $new->reasonPhrase = $reasonPhrase;

        return $new;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): static
    {
        if ($this->protocol === $version) {
            return $this;
        }

        $new = clone $this;
        $new->protocol = $version;

        return $new;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[$this->normalizeHeader($name)]);
    }

    public function getHeader(string $name): array
    {
        if (!$this->hasHeader($name)) {
            return [];
        }

        return $this->headers[$this->headerNames[$this->normalizeHeader($name)]];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, mixed $value): static
    {
        $value = $this->validateAndTrimHeader($name, $value);
        $normalized = $this->normalizeHeader($name);

        $new = clone $this;

        if (isset($new->headerNames[$normalized])) {
            unset($new->headers[$new->headerNames[$normalized]]);
        }

        $new->headerNames[$normalized] = $name;
        $new->headers[$name] = $value;

        return $new;
    }

    public function withAddedHeader(string $name, mixed $value): static
    {
        if ('' === $name) {
            throw new InvalidArgumentException('Header name must be an RFC 7230 compatible string');
        }

        $new = clone $this;
        $new->setHeaders([$name => $value]);

        return $new;
    }

    public function withoutHeader(string $name): static
    {
        if (!$this->hasHeader($name)) {
            return $this;
        }

        $normalized = $this->normalizeHeader($name);
        $header = $this->headerNames[$normalized];
        $new = clone $this;
        unset($new->headers[$header], $new->headerNames[$normalized]);

        return $new;
    }

    public function getBody(): iStream
    {
        if (null === $this->stream) {
            $this->stream = Stream::create('');
        }

        return $this->stream;
    }

    public function withBody(iStream $body): static
    {
        if ($body === $this->stream) {
            return $this;
        }

        $new = clone $this;
        $new->stream = $body;

        return $new;
    }

    /**
     * Filter a set of headers to ensure they are in the correct internal format.
     *
     * Used by message constructors to allow setting all initial headers at once.
     */
    private function setHeaders(array $headers): void
    {
        foreach ($headers as $header => $value) {
            if (is_int($header)) {
                // If a header name was set to a numeric string, PHP will cast the key to an int.
                // We must cast it back to a string in order to comply with validation.
                $header = (string)$header;
            }
            $value = $this->validateAndTrimHeader($header, $value);
            $normalized = $this->normalizeHeader($header);
            if (isset($this->headerNames[$normalized])) {
                $header = $this->headerNames[$normalized];
                $this->headers[$header] = array_merge($this->headers[$header], $value);
            } else {
                $this->headerNames[$normalized] = $header;
                $this->headers[$header] = $value;
            }
        }
    }

    /**
     * Make sure the header complies with RFC 7230.
     *
     * Header names must be a non-empty string consisting of token characters.
     *
     * Header values must be strings consisting of visible characters with all optional
     * leading and trailing whitespace stripped. This method will always strip such
     * optional whitespace. Note that the method does not allow folding whitespace within
     * the values as this was deprecated for almost all instances by the RFC.
     *
     * header-field = field-name ":" OWS field-value OWS
     * field-name   = 1*( "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" / "." / "^"
     *              / "_" / "`" / "|" / "~" / %x30-39 / ( %x41-5A / %x61-7A ) )
     * OWS          = *( SP / HTAB )
     * field-value  = *( ( %x21-7E / %x80-FF ) [ 1*( SP / HTAB ) ( %x21-7E / %x80-FF ) ] )
     *
     * @see https://tools.ietf.org/html/rfc7230#section-3.2.4
     */
    private function validateAndTrimHeader($header, $values): array
    {
        if (!is_string($header) || 1 !== preg_match("@^[!#$%&'*+.^_`|~0-9A-Za-z-]+$@D", $header)) {
            throw new InvalidArgumentException('Header name must be an RFC 7230 compatible string');
        }

        if (!is_array($values)) {
            // This is simple, just one value.
            if ((!is_numeric($values) && !is_string($values)) || 1 !== preg_match(
                    "@^[ \t\x21-\x7E\x80-\xFF]*$@",
                    (string)$values
                )) {
                throw new InvalidArgumentException('Header values must be RFC 7230 compatible strings');
            }

            return [trim((string)$values, " \t")];
        }

        if (empty($values)) {
            throw new InvalidArgumentException(
                'Header values must be a string or an array of strings, empty array given'
            );
        }

        // Assert Non-empty array
        $returnValues = [];
        foreach ($values as $v) {
            if ((!is_numeric($v) && !is_string($v)) || 1 !== preg_match("@^[ \t\x21-\x7E\x80-\xFF]*$@D", (string)$v)) {
                throw new InvalidArgumentException('Header values must be RFC 7230 compatible strings');
            }

            $returnValues[] = trim((string)$v, " \t");
        }

        return $returnValues;
    }

    /**
     * Normalize a header name to lowercase.
     *
     * @param string $name Header name
     *
     * @return string Normalized header name
     */
    private function normalizeHeader(string $name): string
    {
        return strtr($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }
}
