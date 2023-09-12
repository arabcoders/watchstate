<?php

declare(strict_types=1);

namespace App\Libs\Extends;


use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

class HttpClient implements HttpClientInterface, LoggerAwareInterface, ResetInterface
{
    private array $blacklisted = [
        'x-plex-token',
        'x-mediabrowser-token',
    ];

    private LoggerInterface|null $logger = null;

    public function __construct(private HttpClientInterface $client)
    {
    }

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

    public function stream(iterable|ResponseInterface $responses, float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new HttpClient($this->client->withOptions($options));
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
