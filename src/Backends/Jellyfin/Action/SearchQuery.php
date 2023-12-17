<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Class SearchQuery
 *
 * This class is responsible for performing search queries on jellyfin API.
 */
class SearchQuery
{
    use CommonTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.searchQuery';

    /**
     * Class Constructor.
     *
     * @param HttpClientInterface $http The HTTP client.
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

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
            action: $this->action
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
        $url = $context->backendUrl->withPath(
            r('/Users/{user_id}/items/', [
                'user_id' => $context->backendUser
            ])
        )->withQuery(
            http_build_query(
                array_replace_recursive([
                    'searchTerm' => $query,
                    'limit' => $limit,
                    'recursive' => 'true',
                    'fields' => implode(',', JellyfinClient::EXTRA_FIELDS),
                    'enableUserData' => 'true',
                    'enableImages' => 'false',
                    'includeItemTypes' => 'Episode,Movie,Series',
                ], $opts['query'] ?? [])
            )
        );

        $this->logger->debug('Searching [{backend}] libraries for [{query}].', [
            'backend' => $context->backendName,
            'query' => $query,
            'url' => $url
        ]);

        $response = $this->http->request(
            'GET',
            (string)$url,
            array_replace_recursive($context->backendHeaders, $opts['headers'] ?? [])
        );

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Search request for [{query}] in [{backend}] returned with unexpected [{status_code}] status code.',
                    context: [
                        'backend' => $context->backendName,
                        'query' => $query,
                        'status_code' => $response->getStatusCode(),
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug('Parsing Searching [{backend}] libraries for [{query}] payload.', [
                'backend' => $context->backendName,
                'query' => $query,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        $list = [];

        foreach (ag($json, 'Items', []) as $item) {
            $watchedAt = ag($item, 'UserData.LastPlayedDate');
            $year = (int)ag($item, 'Year', 0);

            if (0 === $year && null !== ($airDate = ag($item, 'PremiereDate'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $type = strtolower(ag($item, 'Type'));

            $episodeNumber = ('episode' === $type) ? r('{season}x{episode} - ', [
                'season' => str_pad((string)(ag($item, 'ParentIndexNumber', 0)), 2, '0', STR_PAD_LEFT),
                'episode' => str_pad((string)(ag($item, 'IndexNumber', 0)), 3, '0', STR_PAD_LEFT),
            ]) : null;

            $builder = [
                'id' => ag($item, 'Id'),
                'type' => ucfirst($type),
                'title' => $episodeNumber . mb_substr(ag($item, ['Name', 'OriginalTitle'], '??'), 0, 50),
                'year' => $year,
                'addedAt' => makeDate(ag($item, 'DateCreated', 'now'))->format('Y-m-d H:i:s T'),
                'watchedAt' => null !== $watchedAt ? makeDate($watchedAt)->format('Y-m-d H:i:s T') : 'Never',
            ];

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $builder['raw'] = $item;
            }

            $list[] = $builder;
        }

        return new Response(status: true, response: $list);
    }
}
