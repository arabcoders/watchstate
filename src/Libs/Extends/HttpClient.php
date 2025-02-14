<?php

declare(strict_types=1);

namespace App\Libs\Extends;

use App\Libs\Enums\Http\Method;
use App\Libs\Uri;
use Psr\Log\LoggerAwareInterface as iLoggerAware;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Symfony\Contracts\HttpClient\ResponseStreamInterface as iResponseStream;
use Symfony\Contracts\Service\ResetInterface as iReset;

/**
 * Http Client proxy. This Class acts as proxy in front of the Symfony HttpClient.
 *
 */
class HttpClient implements iHttp, iLoggerAware, iReset
{
    /**
     * @var array $blacklisted An array containing the names of blacklisted headers.
     */
    private array $blacklisted = [
        'x-plex-token',
        'x-mediabrowser-token',
        'authorization'
    ];

    private iLogger|null $logger = null;

    /**
     * Constructor.
     *
     * @param iHttp $client The HTTP client instance.
     * @return void
     */
    public function __construct(private iHttp $client)
    {
    }

    /**
     * Sends an HTTP request.
     *
     * @param string|Method $method The HTTP method (GET, POST, PUT, DELETE, etc.) to use for the request.
     * @param string $url The URL to which the request is sent.
     * @param array $options An optional array of request options.
     *                       Possible options include 'headers' to specify custom request headers,
     *                       'user_data' to provide additional user-defined data as an associate array,
     *                       and other options supported by the underlying HTTP client.
     *
     * @return iResponse The response obtained from the remote server.
     * @throws TransportExceptionInterface If an error occurs while processing the request.
     */
    public function request(string|Method $method, string $url, array $options = []): iResponse
    {
        if (true === ($method instanceof Method)) {
            $method = $method->value;
        }

        if (null !== $this->logger) {
            $headers = [];

            foreach ($options['headers'] ?? [] as $key => $value) {
                $headers[$key] = in_array(strtolower($key), $this->blacklisted) ? '**hidden**' : $value;
            }

            $rUrl = new Uri($url);
            $query = $rUrl->getQuery();
            if (!empty($query)) {
                parse_str($query, $params);
                if (!empty($params)) {
                    $params = array_map(
                        fn($value, $key) => in_array($key, $this->blacklisted) ? '**hidden**' : $value,
                        $params,
                        array_keys($params)
                    );
                    $rUrl = $rUrl->withQuery(http_build_query($params));
                }
            }

            $this->logger->debug('HttpClient - Request [ {method}: {url} ]', [
                'method' => $method,
                'url' => (string)$rUrl,
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
     * @param iterable|iResponse $responses An iterable collection of ResponseInterface objects,
     *                                where each object represents an HTTP response to stream.
     * @param float|null $timeout An optional timeout in seconds for the stream operation.
     *                           If not provided, the default timeout value will be used.
     *
     * @return iResponseStream A ResponseStreamInterface object that allows you to iterate over
     *                                the streamed HTTP responses asynchronously.
     */
    public function stream(iterable|iResponse $responses, ?float $timeout = null): iResponseStream
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

    public function setLogger(iLogger $logger): void
    {
        $this->logger = $logger;
    }

    public function reset(): void
    {
        if ($this->client instanceof iReset) {
            $this->client->reset();
        }
    }
}
