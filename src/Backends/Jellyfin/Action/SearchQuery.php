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

        $jellyfinGuid = $this->jellyfinGuid->withContext($context);

        foreach (ag($json, 'Items', []) as $item) {
            try {
                $entity = $this->createEntity($context, $jellyfinGuid, $item, $opts);
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

        return new Response(status: true, response: $list);
    }
}
