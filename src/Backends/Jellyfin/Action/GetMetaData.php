<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\HttpClient;
use App\Libs\Options;
use DateInterval;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

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
     * @param iHttp $http The HTTP client instance.
     * @param iLogger $logger The logger instance.
     * @param iCache $cache The cache instance.
     */
    public function __construct(protected iHttp $http, protected iLogger $logger, protected iCache $cache)
    {
    }

    /**
     * Get Backend item metadata.
     *
     * @param Context $context Backend context.
     * @param string|int $id the backend id.
     * @param array{query?:array,headers?:array,CACHE_TTL?:DateInterval,NO_CACHE?:bool,LOG_CONTEXT?:array} $opts (Optional) options.
     *
     * @return Response The wrapped response.
     */
    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $id, $opts) {
                $cacheKey = null;
                $isGeneric = true === (bool)ag($opts, Options::IS_GENERIC, false);
                if (true !== (bool)ag($opts, Options::NO_CACHE, false)) {
                    $cacheKey = r("{client}_{split}_{id}_metadata", [
                        'id' => $id,
                        'client' => $context->clientName,
                        'split' => true === $isGeneric ? $context->backendId : $context->backendName,
                    ]);
                }

                $url = $context->backendUrl->withPath(
                    r('/Users/{user_id}/items/{item_id}', ['user_id' => $context->backendUser, 'item_id' => $id])
                )->withQuery(
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

                $logContext = [
                    'id' => $id,
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'url' => (string)$url,
                    ...ag($opts, Options::LOG_CONTEXT, []),
                ];

                $this->logger->debug(
                    "{action}: Requesting '{client}: {user}@{backend}' - '{id}' item metadata.",
                    $logContext
                );

                if (null !== $cacheKey && $this->cache->has($cacheKey)) {
                    $item = $this->cache->get(key: $cacheKey);
                    $fromCache = true;
                } else {
                    assert($this->http instanceof HttpClient);
                    $response = $this->http->request(
                        method: Method::GET,
                        url: (string)$url,
                        options: array_replace_recursive($context->backendHeaders, $opts['headers'] ?? [])
                    );

                    if (Status::OK !== Status::from($response->getStatusCode())) {
                        return new Response(
                            status: false,
                            error: new Error(
                                message: "{action}: Request for '{client}: {user}@{backend}' - '{id}' item returned with unexpected '{status_code}' status code.",
                                context: [
                                    ...$logContext,
                                    'status_code' => $response->getStatusCode(),
                                ]
                            )
                        );
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
                    $this->logger->debug("{action}: Processing '{client}: {user}@{backend}' - '{id}' item payload.", [
                        ...$logContext,
                        'cached' => $fromCache,
                        'response' => [
                            'body' => $item
                        ],
                    ]);
                }

                return new Response(status: true, response: $item, extra: ['cached' => $fromCache]);
            },
            action: $this->action,
        );
    }
}
