<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Response;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\HttpClient;
use App\Libs\Options;
use DateInterval;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GetMetaData
{
    use CommonTrait;

    private string $action = 'plex.getMetadata';

    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
        protected readonly iCache $cache
    ) {
    }

    /**
     * Get metadata about specific item from Backend.
     *
     * @param Context $context
     * @param string|int $id the backend id.
     * @param array{query?:array,headers?:array,CACHE_TTL?:DateInterval,NO_CACHE?:bool,LOG_CONTEXT?:array} $opts (Optional) options.
     *
     * @return Response
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

                $url = $context->backendUrl->withPath('/library/metadata/' . $id)
                    ->withQuery(http_build_query(array_merge_recursive(['includeGuids' => 1], $opts['query'] ?? [])));

                $logContext = [
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'url' => (string)$url,
                    'id' => $id,
                    ...ag($opts, Options::LOG_CONTEXT, []),
                ];

                $this->logger->debug(
                    message: "{action}: Requesting '{client}: {user}@{backend}' - '{id}' item metadata.",
                    context: $logContext
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
                                context: [...$logContext, 'status_code' => $response->getStatusCode()]
                            )
                        );
                    }

                    $content = $response->getContent();

                    $item = json_decode(
                        json: $content,
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
                    $this->logger->debug(
                        message: "{action}: Processing '{client}: {user}@{backend}' - '{id}' item payload.",
                        context: [...$logContext, 'cached' => $fromCache, 'response' => ['body' => $item]]
                    );
                }

                return new Response(status: true, response: $item, extra: ['cached' => $fromCache]);
            },
            action: $this->action
        );
    }
}
