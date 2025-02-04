<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Response;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use DateInterval;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GetMetaData
{
    use CommonTrait;

    private string $action = 'plex.getMetadata';

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * Get metadata about specific item from Backend.
     *
     * @param Context $context
     * @param string|int $id the backend id.
     * @param array $opts optional options.
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

                $this->logger->debug("{client}: Requesting '{backend}: {id}' item metadata.", [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'id' => $id,
                    'url' => $url
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
                    $this->logger->debug("{client}: Processing '{backend}: {id}' item payload.", [
                        'id' => $id,
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'cached' => $fromCache,
                        'trace' => $item,
                    ]);
                }

                return new Response(status: true, response: $item, extra: ['cached' => $fromCache]);
            },
            action: $this->action
        );
    }
}
