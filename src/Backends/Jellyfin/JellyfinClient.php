<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin;

use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\GetIdentifier;
use App\Backends\Jellyfin\Action\GetMetaData;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class JellyfinClient
{
    public const TYPE_MOVIE = 'Movie';
    public const TYPE_SHOW = 'Series';
    public const TYPE_EPISODE = 'Episode';

    public const COLLECTION_TYPE_SHOWS = 'tvshows';
    public const COLLECTION_TYPE_MOVIES = 'movies';

    public const EXTRA_FIELDS = [
        'ProviderIds',
        'DateCreated',
        'OriginalTitle',
        'SeasonUserData',
        'DateLastSaved',
        'PremiereDate',
        'ProductionYear',
        'Path',
    ];

    public const TYPE_MAPPER = [
        JellyfinClient::TYPE_SHOW => iState::TYPE_SHOW,
        JellyfinClient::TYPE_MOVIE => iState::TYPE_MOVIE,
        JellyfinClient::TYPE_EPISODE => iState::TYPE_EPISODE,
    ];

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
