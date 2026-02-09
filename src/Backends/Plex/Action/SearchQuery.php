<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexGuid;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class SearchQuery
{
    use CommonTrait;
    use PlexActionTrait;

    private string $action = 'plex.searchQuery';

    public function __construct(
        protected iHttp $http,
        protected iLogger $logger,
        private iDB $db,
        private PlexGuid $plexGuid,
    ) {}

    /**
     * Get Users list.
     *
     * @param Context $context
     * @param string $query
     * @param int $limit
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string $query, int $limit = 25, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->search($context, $query, $limit, $opts),
            action: $this->action,
        );
    }

    /**
     * Search Backend Titles.
     *
     * @throws ExceptionInterface if the request failed
     * @throws JsonException if the response cannot be parsed
     */
    private function search(Context $context, string $query, int $limit = 25, array $opts = []): Response
    {
        $url = $context
            ->backendUrl
            ->withPath('/hubs/search')
            ->withQuery(
                http_build_query(
                    array_replace_recursive(
                        [
                            'query' => $query,
                            'limit' => $limit,
                            'includeGuids' => 1,
                            'includeLocations' => 1,
                            'includeExternalMedia' => 0,
                            'includeCollections' => 0,
                        ],
                        $opts['query'] ?? [],
                    ),
                ),
            );

        $logContext = [
            'query' => $query,
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'url' => (string) $url,
        ];

        $this->logger->debug("{action}: Searching '{client}: {user}@{backend}' libraries for '{query}'.", $logContext);

        $response = $this->http->request(
            method: Method::GET,
            url: (string) $url,
            options: array_replace_recursive(
                $context->getHttpOptions(),
                true === ag_exists($opts, 'headers') ? ['headers' => $opts['headers']] : [],
            ),
        );

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Search request for '{query}' in '{client}: {user}@{backend}' returned with unexpected '{status_code}' status code.",
                    context: [
                        ...$logContext,
                        'status_code' => $response->getStatusCode(),
                    ],
                    level: Levels::ERROR,
                ),
            );
        }

        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        if ($context->trace) {
            $this->logger->debug(
                message: "{action}: Parsing Searching '{client}: {user}@{backend}' libraries for '{query}' payload.",
                context: [...$logContext, 'response' => ['body' => $json]],
            );
        }

        $list = [];

        $plexGuid = $this->plexGuid->withContext($context);

        foreach (ag($json, 'MediaContainer.Hub', []) as $leaf) {
            $type = strtolower(ag($leaf, 'type', ''));

            if (false === $this->isSupportedType($type)) {
                continue;
            }

            foreach (ag($leaf, 'Metadata', []) as $item) {
                try {
                    $entity = $this->createEntity($context, $plexGuid, $item, $opts);
                } catch (Throwable $e) {
                    $this->logger->error(
                        message: "{action}: Failed to map '{client}: {user}@{backend}' item to entity. {error}",
                        context: [...$logContext, 'error' => $e->getMessage()],
                    );
                    continue;
                }

                if (null !== ($localEntity = $this->db->get($entity))) {
                    $entity->id = $localEntity->id;
                }

                $builder = $entity->getAll();
                if (true === (bool) ag($opts, Options::RAW_RESPONSE)) {
                    $builder[Options::RAW_RESPONSE] = $item;
                }
                $list[] = $builder;
            }
        }

        return new Response(status: true, response: $list);
    }
}
