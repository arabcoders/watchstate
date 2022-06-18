<?php

declare(strict_types=1);

namespace App\Backends\Emby;

use App\Backends\Common\Context;
use App\Backends\Emby\Action\GetMetaData;
use App\Backends\Emby\Action\GetIdentifier;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Container;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EmbyClient
{
    public const TYPE_MOVIE = JellyfinClient::TYPE_MOVIE;
    public const TYPE_SHOW = JellyfinClient::TYPE_SHOW;
    public const TYPE_EPISODE = JellyfinClient::TYPE_EPISODE;

    public const COLLECTION_TYPE_SHOWS = JellyfinClient::COLLECTION_TYPE_SHOWS;
    public const COLLECTION_TYPE_MOVIES = JellyfinClient::COLLECTION_TYPE_MOVIES;

    public const EXTRA_FIELDS = JellyfinClient::EXTRA_FIELDS;

    private Context|null $context = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected CacheInterface $cache,
        protected LoggerInterface $logger,
    ) {
    }

    public function withContext(Context $context): self
    {
        $cloned = clone $this;
        $cloned->context = $context;

        return $cloned;
    }

    public function getMetadata(string|int $id, array $opts = []): array
    {
        $response = Container::get(GetMetaData::class)(context: $this->context, id: $id, opts: $opts);

        if (!$response->isSuccessful()) {
            throw new RuntimeException(message: $response->error->format(), previous: $response->error->previous);
        }

        return $response->response;
    }

    public function getIdentifier(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->context->backendId) {
            return $this->context->backendId;
        }

        $response = Container::get(GetIdentifier::class)(context: $this->context);

        return $response->isSuccessful() ? $response->response : null;
    }
}
