<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Http Client proxy. This Class acts as proxy in front of the Symfony HttpClient.
 *
 */
class HttpClient implements HttpClientInterface, LoggerAwareInterface, ResetInterface
{
    /**
     * @var array $blacklisted An array containing the names of blacklisted headers.
     */
    private array $blacklisted = [
        'x-plex-token',
        'x-mediabrowser-token',
        'authorization'
    ];

    private LoggerInterface|null $logger = null;

    /**
     * Constructor.
     *
     * @param HttpClientInterface $client The HTTP client instance.
     * @return void
     */
    public function __construct(private HttpClientInterface $client)
    {
    }

    /**
     * Sends an HTTP request.
     *
     * @param string $method The HTTP method (GET, POST, PUT, DELETE, etc.) to use for the request.
     * @param string $url The URL to which the request is sent.
     * @param array $options An optional array of request options.
     *                       Possible options include 'headers' to specify custom request headers,
     *                       'user_data' to provide additional user-defined data as an associate array,
     *                       and other options supported by the underlying HTTP client.
     *
     * @return ResponseInterface The response obtained from the remote server.
     * @throws TransportExceptionInterface If an error occurs while processing the request.
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (null !== $this->logger) {
            $headers = [];

            foreach ($options['headers'] ?? [] as $key => $value) {
                $headers[$key] = in_array(strtolower($key), $this->blacklisted) ? '**hidden**' : $value;
            }

            $this->logger->debug('HttpClient - Request [ {method}: {url}]', [
                'method' => $method,
                'url' => $url,
                'options' => array_replace_recursive($options, [
                    'headers' => $headers,
                    'user_data' => [
                        'ok' => 'callable',
                        'error' => 'callable'
                    ],
                ])
            ]);
        }

        return $this->client->request($method, $url, $options);
    }

    /**
     * Streams multiple HTTP responses asynchronously.
     *
     * @param iterable|ResponseInterface $responses An iterable collection of ResponseInterface objects,
     *                                where each object represents an HTTP response to stream.
     * @param float|null $timeout An optional timeout in seconds for the stream operation.
     *                           If not provided, the default timeout value will be used.
     *
     * @return ResponseStreamInterface A ResponseStreamInterface object that allows you to iterate over
     *                                the streamed HTTP responses asynchronously.
     */
    public function stream(iterable|ResponseInterface $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * Returns a new instance of the HttpClient class with the specified options.
     *
     * @param array $options The array of options to be included in the new HttpClient instance.
     *                       These options can be used to modify the behavior of the HTTP client.
     *
     * @return static A new instance of the HttpClient class with the specified options.
     */
    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options));
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
