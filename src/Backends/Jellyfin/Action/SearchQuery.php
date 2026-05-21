<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient;
use App\Backends\Jellyfin\JellyfinGuid;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

/**
 * Class SearchQuery
 *
 * This class is responsible for performing search queries on jellyfin API.
 */
class SearchQuery
{
    use CommonTrait;
    use JellyfinActionTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.searchQuery';

    /**
     * @param iHttp&\App\Libs\Extends\HttpClient $http
     * @param iLogger $logger
     * @param JellyfinGuid $jellyfinGuid
     * @param iDB $db
     */
    public function __construct(
        protected iHttp $http,
        protected iLogger $logger,
        private JellyfinGuid $jellyfinGuid,
        private iDB $db,
    ) {}

    /**
     * Wrap the operation in a try response block.
     *
     * @param Context $context Backend context.
     * @param string $query The query to search for.
     * @param int $limit The maximum number of results to return.
     * @param array $opts (optional) options.
     *
     * @return Response The response.
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
     * Perform search query on jellyfin API.
     *
     * @param Context $context Backend context.
     * @param string $query The query to search for.
     * @param int $limit The maximum number of results to return.
     * @param array $opts (optional) options.
     *
     * @throws ExceptionInterface When the request fails.
     * @throws JsonException When the response is not valid JSON.
     */
    private function search(Context $context, string $query, int $limit = 25, array $opts = []): Response
    {
        $url = $context
            ->backendUrl
            ->withPath(
                path: r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]),
            )
            ->withQuery(
                http_build_query(
                    array_replace_recursive([
                        'searchTerm' => $query,
                        'limit' => $limit,
                        'recursive' => 'true',
                        'fields' => implode(',', JellyfinClient::EXTRA_FIELDS),
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                        'includeItemTypes' => implode(',', array_keys(JellyfinClient::TYPE_MAPPER)),
                    ], $opts['query'] ?? []),
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

        $this->logger->debug("Searching '{user}@{backend}' for '{query}'.", [
            ...$logContext,
            'event_name' => 'backend.request.started',
            'subsystem' => 'backend.search',
            'operation' => 'query',
            'outcome' => 'started',
            'http' => ['url' => (string) $url],
        ]);

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
                    message: "Search request for '{query}' on '{user}@{backend}' returned status {http.status_code}.",
                    context: [
                        ...$logContext,
                        'event_name' => 'backend.response.failed',
                        'subsystem' => 'backend.search',
                        'operation' => 'query',
                        'outcome' => 'failed',
                        'reason' => 'unexpected_status',
                        'http' => [
                            'status_code' => $response->getStatusCode(),
                            'expected_status_codes' => [Status::OK->value],
                            'url' => (string) $url,
                        ],
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
                message: "Processing search response from '{user}@{backend}' for '{query}'.",
                context: [
                    ...$logContext,
                    'event_name' => 'backend.response.received',
                    'subsystem' => 'backend.search',
                    'operation' => 'query',
                    'outcome' => 'received',
                    'response' => ['body' => $json],
                ],
            );
        }

        $list = [];

        $jellyfinGuid = $this->jellyfinGuid->withContext($context);

        foreach (ag($json, 'Items', []) as $item) {
            try {
                $entity = $this->createEntity($context, $jellyfinGuid, $item, $opts);
            } catch (Throwable $e) {
                $this->logger->error(
                    message: "Failed to map search result from '{user}@{backend}' to a local entity.",
                    context: [
                        ...$logContext,
                        'event_name' => 'backend.operation.failed',
                        'subsystem' => 'backend.search',
                        'operation' => 'map_result',
                        'outcome' => 'failed',
                        ...exception_log($e),
                    ],
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

        return new Response(status: true, response: $list);
    }
}
