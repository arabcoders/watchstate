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

class SearchQuery
{
    use CommonTrait;

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
        return $this->tryResponse(context: $context, fn: fn() => $this->search($context, $query, $limit, $opts));
    }

    /**
     * Search Backend Titles.
     *
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function search(Context $context, string $query, int $limit = 25, array $opts = []): Response
    {
        $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/', $context->backendUser))->withQuery(
            http_build_query(
                array_replace_recursive(
                    [
                        'searchTerm' => $query,
                        'limit' => $limit,
                        'recursive' => 'true',
                        'fields' => implode(',', JellyfinClient::EXTRA_FIELDS),
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                        'includeItemTypes' => 'Episode,Movie,Series',
                    ],
                    $opts['query'] ?? []
                )
            )
        );

        $this->logger->debug('Searching [%(backend)] libraries for [%(query)].', [
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
                    message: 'Search request for [%(query)] in [%(backend)] returned with unexpected [%(status_code)] status code.',
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
            $this->logger->debug('Parsing Searching [%(backend)] libraries for [%(query)] payload.', [
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

            $episodeNumber = ('episode' === $type) ? sprintf(
                '%sx%s - ',
                str_pad((string)(ag($item, 'ParentIndexNumber', 0)), 2, '0', STR_PAD_LEFT),
                str_pad((string)(ag($item, 'IndexNumber', 0)), 3, '0', STR_PAD_LEFT),
            ) : null;

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
