<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

/**
 * Class GetLibrary
 *
 * This class retrieves library content from jellyfin API.
 */
class GetLibrary
{
    use CommonTrait;
    use JellyfinActionTrait;

    protected string $action = 'jellyfin.getLibrary';

    /**
     * Class constructor
     *
     * @param iHttp $http The HTTP client object.
     * @param iLogger $logger The logger object.
     */
    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
    }

    /**
     * Get library content.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param string|int $id
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, iGuid $guid, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $guid, $id, $opts),
            action: $this->action
        );
    }

    /**
     * Fetches library content from the backend.
     *
     * @param Context $context Backend context.
     * @param string|int $id The library id.
     * @param array $opts (optional) Options.
     *
     * @return Response The response object containing the library content.
     * @throws ExceptionInterface If the backend request fails.
     * @throws RuntimeException When the API call was not successful.
     * @throws \JsonMachine\Exception\InvalidArgumentException If the backend response is not a valid JSON.
     * @throws InvalidArgumentException
     */
    private function action(Context $context, iGuid $guid, string|int $id, array $opts = []): Response
    {
        $libraries = $this->getBackendLibraries($context);

        if (null === ($section = ag($libraries, $id))) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'No Library with id [{id}] found in [{backend}] response.',
                    context: [
                        'id' => $id,
                        'backend' => $context->backendName,
                        'response' => [
                            'body' => $libraries
                        ],
                    ],
                    level: Levels::WARNING
                ),
            );
        }

        unset($libraries);

        $logContext = [
            'library' => [
                'id' => $id,
                'type' => ag($section, 'CollectionType', 'unknown'),
                'title' => ag($section, 'Name', '??'),
            ],
        ];

        if (true !== in_array(
                ag($logContext, 'library.type'),
                [JellyfinClient::COLLECTION_TYPE_MOVIES, JellyfinClient::COLLECTION_TYPE_SHOWS]
            )) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'The Requested [{backend}] Library [{library.id}: {library.title}] returned with unsupported type [{library.type}].',
                    context: [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ],
                    level: Levels::WARNING
                ),
            );
        }

        $extraQueryParams = [];

        if (null !== ($limit = ag($opts, Options::LIMIT_RESULTS))) {
            $extraQueryParams['Limit'] = (int)$limit;
        }

        $url = $context->backendUrl->withPath(
            r('/Users/{user_id}/items/', ['user_id' => $context->backendUser])
        )->withQuery(
            http_build_query([
                'parentId' => $id,
                'enableUserData' => 'false',
                'enableImages' => 'false',
                'excludeLocationTypes' => 'Virtual',
                'include' => implode(',', [JellyfinClient::TYPE_SHOW, JellyfinClient::TYPE_MOVIE]),
                'fields' => implode(',', JellyfinClient::EXTRA_FIELDS),
                ...$extraQueryParams,
            ])
        );

        $logContext['library']['url'] = (string)$url;

        $this->logger->debug('Requesting [{backend}] library [{library.title}] content.', [
            'backend' => $context->backendName,
            ...$logContext,
        ]);

        $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [{backend}] library [{library.title}] returned with unexpected [{status_code}] status code.',
                    context: [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                        ...$logContext,
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $it = Items::fromIterable(
            iterable: httpClientChunks($this->http->stream($response)),
            options: [
                'pointer' => '/Items',
                'decoder' => new ErrorWrappingDecoder(
                    new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE)
                )
            ]
        );

        $list = [];

        foreach ($it as $entity) {
            if ($entity instanceof DecodingError) {
                $this->logger->warning(
                    'Failed to decode one item of [{backend}] library [{library.title}] content.',
                    [
                        'backend' => $context->backendName,
                        ...$logContext,
                        'error' => [
                            'message' => $entity->getErrorMessage(),
                            'body' => $entity->getMalformedJson(),
                        ],
                    ]
                );
                continue;
            }

            if (false === $this->isSupportedType(ag($entity, 'Type'))) {
                continue;
            }

            $url = $context->backendUrl->withPath(
                sprintf('/Users/%s/items/%s', $context->backendUser, ag($entity, 'Id'))
            );

            $logContext['item'] = [
                'id' => ag($entity, 'Id'),
                'title' => ag($entity, ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'], '??'),
                'year' => ag($entity, 'ProductionYear', '0000'),
                'type' => ag($entity, 'Type'),
                'url' => (string)$url,
            ];

            // -- Handle multi episode entries.
            $indexNumber = ag($entity, 'IndexNumber');
            $indexNumberEnd = ag($entity, 'IndexNumberEnd');
            if (null !== $indexNumber && null !== $indexNumberEnd && $indexNumberEnd > $indexNumber) {
                $episodeRangeLimit = (int)ag($context->options, Options::MAX_EPISODE_RANGE, 5);
                $range = range(ag($entity, 'IndexNumber'), $indexNumberEnd);
                if (count($range) > $episodeRangeLimit) {
                    $list[] = $this->process($context, $entity, $logContext, $opts);
                } else {
                    foreach (range((int)ag($entity, 'IndexNumber'), $indexNumberEnd) as $i) {
                        $entity['IndexNumber'] = $i;
                        $list[] = $this->process($context, $entity, $logContext, $opts);
                    }
                }
            } else {
                $list[] = $this->process($context, $guid, $entity, $logContext, $opts);
            }
        }

        return new Response(status: true, response: $list);
    }

    /**
     * Process the given item.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The guid object.
     * @param array $item The item to be processed.
     * @param array $log (optional) The log array.
     * @param array $opts (optional) The options array.
     *
     * @return array<string,mixed>|iState The processed metadata.
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    private function process(
        Context $context,
        iGuid $guid,
        array $item,
        array $log = [],
        array $opts = []
    ): array|iState {
        if (true === (bool)ag($opts, Options::TO_ENTITY)) {
            return $this->createEntity($context, $guid, $item, $opts);
        }

        $url = $context->backendUrl->withPath(sprintf('/Users/%s/items/%s', $context->backendUser, ag($item, 'Id')));
        $possibleTitlesList = ['Name', 'OriginalTitle', 'SortName', 'ForcedSortName'];

        $data = [
            'backend' => $context->backendName,
            ...$log,
        ];

        if ($context->trace) {
            $data['trace'] = $item;
        }

        $this->logger->debug('Processing [{backend}] {item.type} [{item.title} ({item.year})].', $data);

        $webUrl = $url->withPath('/web/index.html')->withFragment(r('!/{action}?id={id}&serverId={backend_id}', [
            'backend_id' => $context->backendId,
            'id' => ag($item, 'Id'),
            'action' => JellyfinClient::CLIENT_NAME === $context->clientName ? 'details' : 'item',
        ]));

        $metadata = [
            iState::COLUMN_ID => ag($item, 'Id'),
            iState::COLUMN_TYPE => ucfirst(ag($item, 'Type', 'unknown')),
            iState::COLUMN_META_LIBRARY => ag($log, 'library.title'),
            'url' => (string)$url,
            'webUrl' => (string)$webUrl,
            iState::COLUMN_TITLE => ag($item, $possibleTitlesList, '??'),
            iState::COLUMN_YEAR => ag($item, 'ProductionYear'),
            iState::COLUMN_GUIDS => [],
            'match' => [
                'titles' => [],
                'paths' => [],
            ],
        ];

        foreach ($possibleTitlesList as $title) {
            if (null === ($title = ag($item, $title))) {
                continue;
            }

            $isASCII = mb_detect_encoding($title, 'ASCII', true);
            $title = trim($isASCII ? strtolower($title) : mb_strtolower($title));

            if (true === in_array($title, $metadata['match']['titles'])) {
                continue;
            }

            $metadata['match']['titles'][] = $title;
        }

        if (null !== ($path = ag($item, 'Path'))) {
            $metadata['match']['paths'][] = [
                'full' => $path,
                'short' => basename($path),
            ];

            if (ag($item, 'Type') === 'Movie') {
                if (false === str_starts_with(basename($path), basename(dirname($path)))) {
                    $metadata['match']['paths'][] = [
                        'full' => $path,
                        'short' => basename($path),
                    ];
                }
            }
        }

        if (null !== ($providerIds = ag($item, 'ProviderIds'))) {
            foreach ($providerIds as $key => $val) {
                $metadata[iState::COLUMN_GUIDS][] = $key . '://' . $val;
            }
        }

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $metadata[Options::RAW_RESPONSE] = $item;
        }

        return $metadata;
    }
}
