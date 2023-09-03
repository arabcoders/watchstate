<?php

declare(strict_types=1);

namespace App\Libs\Extends;


use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

final class HttpClient implements HttpClientInterface, LoggerAwareInterface, ResetInterface
{
    private LoggerInterface|null $logger = null;

    public function __construct(private HttpClientInterface $client)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->logger?->notice('HttpClient [ %(method): %(url)]', [
            'method' => $method,
            'url' => $url,
            'options' => $options,
        ]);

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
        if ($this->client instanceof LoggerAwareInterface) {
            $this->client->setLogger($logger);
        }

        $this->logger = $logger;
    }

    public function reset()
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
