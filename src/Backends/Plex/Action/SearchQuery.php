<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SearchQuery
{
    use CommonTrait;

    private string $action = 'plex.searchQuery';

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

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
            action: $this->action
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
        $url = $context->backendUrl->withPath('/hubs/search')->withQuery(
            http_build_query(
                array_replace_recursive(
                    [
                        'query' => $query,
                        'limit' => $limit,
                        'includeGuids' => 1,
                        'includeExternalMedia' => 0,
                        'includeCollections' => 0,
                    ],
                    $opts['query'] ?? []
                )
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

        $this->logger->debug('Requesting [{backend}] Users list.', [
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

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

        foreach (ag($json, 'MediaContainer.Hub', []) as $leaf) {
            $type = ag($leaf, 'type');

            if ('show' !== $type && 'movie' !== $type && 'episode' !== $type) {
                continue;
            }

            foreach (ag($leaf, 'Metadata', []) as $item) {
                $watchedAt = ag($item, 'lastViewedAt');

                $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
                if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
                    $year = (int)makeDate($airDate)->format('Y');
                }

                $episodeNumber = ('episode' === $type) ? r('{season}x{episode} - ', [
                    'season' => str_pad((string)(ag($item, 'parentIndex', 0)), 2, '0', STR_PAD_LEFT),
                    'episode' => str_pad((string)(ag($item, 'index', 0)), 3, '0', STR_PAD_LEFT),
                ]) : null;

                $builder = [
                    'id' => (int)ag($item, 'ratingKey'),
                    'type' => ucfirst(ag($item, 'type', '??')),
                    'library' => ag($item, 'librarySectionTitle', '??'),
                    'title' => $episodeNumber . mb_substr(ag($item, ['title', 'originalTitle'], '??'), 0, 50),
                    'year' => $year,
                    'addedAt' => makeDate(ag($item, 'addedAt'))->format('Y-m-d H:i:s T'),
                    'watchedAt' => null !== $watchedAt ? makeDate($watchedAt)->format('Y-m-d H:i:s T') : 'Never',
                ];

                if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                    $builder['raw'] = $item;
                }

                $list[] = $builder;
            }
        }

        return new Response(status: true, response: $list);
    }
}
