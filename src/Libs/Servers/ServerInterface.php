<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Entity\StateEntity;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use DateTimeInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

interface ServerInterface
{
    /**
     * Initiate Server. It should return **NEW OBJECT**
     *
     * @param string $name
     * @param Uri $url
     * @param null|int|string $token
     * @param array $options
     *
     * @return self
     */
    public function setUp(string $name, Uri $url, null|string|int $token = null, array $options = []): self;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     *
     * @return ServerInterface
     */
    public function setLogger(LoggerInterface $logger): ServerInterface;

    /**
     * Parse Server Specific Webhook event. for play/unplayed event.
     *
     * @param ServerRequestInterface $request
     * @return StateEntity|null
     */
    public static function parseWebhook(ServerRequestInterface $request): StateEntity|null;

    /**
     * Import Watch state.
     *
     * @param ImportInterface $mapper
     * @param DateTimeInterface|null $after
     *
     * @return array<array-key,PromiseInterface>
     */
    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array;

    /**
     * Export Watch State to Server.
     *
     * @param ExportInterface $mapper
     * @param DateTimeInterface|null $after
     *
     * @return array<array-key,PromiseInterface>
     */
    public function push(ExportInterface $mapper, DateTimeInterface|null $after = null): array;
}
