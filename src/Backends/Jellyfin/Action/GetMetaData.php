<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use DateInterval;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class GetMetaData
 *
 * This class retrieves metadata about a specific item from jellyfin API.
 */
class GetMetaData
{
    use CommonTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.getMetadata';

    /**
     * Class Constructor.
     *
     * @param HttpClientInterface $http The HTTP client instance.
     * @param LoggerInterface $logger The logger instance.
     * @param CacheInterface $cache The cache instance.
     */
    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * Get Backend item metadata.
     *
     * @param Context $context Backend context.
     * @param string|int $id the backend id.
     * @param array $opts (Optional) options.
     *
     * @return Response The wrapped response.
     */
    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $id, $opts) {
                if (true === (bool)ag($opts, Options::NO_CACHE, false)) {
                    $cacheKey = null;
                } else {
                    $cacheKey = $context->clientName . '_' . $context->backendName . '_' . $id . '_metadata';
                }

                $url = $context->backendUrl
                    ->withPath(
                        r('/Users/{user_id}/items/{item_id}', [
                            'user_id' => $context->backendUser,
                            'item_id' => $id
                        ])
                    )
                    ->withQuery(
                        http_build_query(
                            array_merge_recursive([
                                'recursive' => 'false',
                                'fields' => implode(',', JellyfinClient::EXTRA_FIELDS),
                                'enableUserData' => 'true',
                                'enableImages' => 'false',
                                'includeItemTypes' => 'Episode,Movie,Series',
                            ], $opts['query'] ?? []),
                        )
                    );

                $this->logger->debug("{client}: Requesting '{backend}: {id}' item metadata.", [
                    'id' => $id,
                    'url' => $url,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                ]);

                if (null !== $cacheKey && $this->cache->has($cacheKey)) {
                    $item = $this->cache->get(key: $cacheKey);
                    $fromCache = true;
                } else {
                    $response = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($context->backendHeaders, $opts['headers'] ?? [])
                    );

                    if (Status::OK !== Status::from($response->getStatusCode())) {
                        $response = new Response(
                            status: false,
                            error: new Error(
                                message: "{client} Request for '{backend}: {id}' item returned with unexpected '{status_code}' status code.",
                                context: [
                                    'id' => $id,
                                    'client' => $context->clientName,
                                    'backend' => $context->backendName,
                                    'status_code' => $response->getStatusCode(),
                                ]
                            )
                        );
                        $context->logger?->error($response->getError()->message, $response->getError()->context);
                        return $response;
                    }

                    $item = json_decode(
                        json: $response->getContent(),
                        associative: true,
                        flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                    );

                    if (null !== $cacheKey) {
                        $this->cache->set(
                            key: $cacheKey,
                            value: $item,
                            ttl: $opts[Options::CACHE_TTL] ?? new DateInterval('PT5M')
                        );
                    }

                    $fromCache = false;
                }

                if (true === $context->trace) {
                    $this->logger->debug("{client} Processing '{backend}: {id}' item payload.", [
                        'id' => $id,
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'cached' => $fromCache,
                        'trace' => $item,
                    ]);
                }

                return new Response(status: true, response: $item, extra: ['cached' => $fromCache]);
            },
            action: $this->action,
        );
    }
}
