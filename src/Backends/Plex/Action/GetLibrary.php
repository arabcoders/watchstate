<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\RuntimeException;
use App\Libs\Options;
use JsonException;
use JsonMachine\Exception\InvalidArgumentException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GetLibrary
{
    use CommonTrait;
    use PlexActionTrait;

    private string $action = 'plex.getLibrary';

    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
    }

    /**
     * Get Library content.
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
     * @throws ExceptionInterface
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws \App\Libs\Exceptions\Backends\InvalidArgumentException
     */
    private function action(Context $context, iGuid $guid, string|int $id, array $opts = []): Response
    {
        $libraries = $this->getBackendLibraries($context);

        $deepScan = true === (bool)ag($opts, Options::MISMATCH_DEEP_SCAN);

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
                'type' => ag($section, 'type', 'unknown'),
                'agent' => ag($section, 'agent', 'unknown'),
                'title' => ag($section, 'title', '??'),
            ],
        ];

        if (true !== in_array(ag($logContext, 'library.type'), [PlexClient::TYPE_MOVIE, PlexClient::TYPE_SHOW])) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'The Requested [{backend}] Library [{library.title}] returned with unsupported type [{library.type}].',
                    context: [
                        'backend' => $context->backendName,
                        ...$logContext,
                    ],
                    level: Levels::WARNING
                ),
            );
        }

        $extraHeaders = [
            'headers' => [],
        ];

        if (null !== ($limit = ag($opts, Options::LIMIT_RESULTS))) {
            $extraHeaders['headers']['X-Plex-Container-Start'] = 0;
            $extraHeaders['headers']['X-Plex-Container-Size'] = (int)$limit;
        }

        $url = $context->backendUrl
            ->withPath(r('/library/sections/{library_id}/all', ['library_id' => $id]))
            ->withQuery(
                http_build_query([
                    'type' => PlexClient::TYPE_MOVIE === ag($logContext, 'library.type') ? 1 : 2,
                    'includeGuids' => 1,
                ])
            );

        $logContext['library']['url'] = (string)$url;

        $this->logger->debug('Requesting [{backend}] library [{library.title}] content.', [
            'backend' => $context->backendName,
            ...$logContext,
        ]);

        $response = $this->http->request(
            'GET',
            (string)$url,
            array_replace_recursive($context->backendHeaders, $extraHeaders)
        );

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
            iterable: httpClientChunks(
                stream: $this->http->stream($response)
            ),
            options: [
                'pointer' => '/MediaContainer/Metadata',
                'decoder' => new ErrorWrappingDecoder(
                    innerDecoder: new ExtJsonDecoder(
                        assoc: true,
                        options: JSON_INVALID_UTF8_IGNORE
                    )
                )
            ]
        );

        $list = $requests = [];

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

            if (false === $this->isSupportedType(ag($entity, 'type'))) {
                continue;
            }

            $year = (int)ag($entity, 'year', 0);

            if (0 === $year && null !== ($airDate = ag($entity, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $logContext['item'] = [
                'id' => ag($entity, 'ratingKey'),
                'title' => ag($entity, ['title', 'originalTitle'], '??'),
                'year' => $year,
                'type' => ag($entity, 'type'),
            ];

            if (false === $deepScan || PlexClient::TYPE_MOVIE === ag($logContext, 'item.type')) {
                $list[] = $this->process($context, $guid, $entity, $logContext, $opts);
            } else {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$context->backendUrl->withPath(
                        r('/library/metadata/{item_id}', ['item_id' => ag($logContext, 'item.id')])
                    ),
                    $context->backendHeaders + [
                        'user_data' => [
                            'context' => $logContext
                        ]
                    ]
                );
            }
        }

        if (!empty($requests)) {
            $this->logger->info(
                'Requesting [{total}] items metadata from [{backend}] library [{library.title}].',
                [
                    'total' => number_format(count($requests)),
                    'backend' => $context->backendName,
                    ...$logContext
                ]
            );
        }

        $noLog = (bool)ag($opts, Options::NO_LOGGING);

        foreach ($requests as $response) {
            $requestContext = ag($response->getInfo('user_data'), 'context', []);

            try {
                if (200 !== $response->getStatusCode()) {
                    if (false === $noLog) {
                        $this->logger->warning(
                            "Request for '{client}: {backend}' {item.type} '{item.title}' metadata returned with unexpected '{status_code}' status code.",
                            [
                                'client' => $context->clientName,
                                'backend' => $context->backendName,
                                'status_code' => $response->getStatusCode(),
                                'body' => $response->getContent(false),
                                ...$requestContext
                            ]
                        );
                    }
                    continue;
                }

                $json = json_decode(
                    json: $response->getContent(),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                $list[] = $this->process(
                    $context,
                    $guid,
                    ag($json, 'MediaContainer.Metadata.0', []),
                    $requestContext,
                    $opts
                );
            } catch (JsonException|HttpExceptionInterface $e) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] request for {item.type} [{item.title}] metadata. Error [{error.message} @ {error.file}:{error.line}].',
                        context: [
                            'backend' => $context->backendName,
                            'client' => $context->clientName,
                            'error' => [
                                'kind' => $e::class,
                                'line' => $e->getLine(),
                                'message' => $e->getMessage(),
                                'file' => after($e->getFile(), ROOT_PATH),
                            ],
                            'exception' => [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                                'message' => $e->getMessage(),
                                'trace' => $e->getTrace(),
                            ],
                            ...$requestContext,
                        ],
                        level: Levels::WARNING,
                        previous: $e
                    )
                );
            }
        }

        return new Response(status: true, response: $list);
    }

    /**
     * Process a single item.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The GUID object.
     * @param array $item The item array.
     * @param array $log The log array. Default is an empty array.
     * @param array $opts The options array. Default is an empty array.
     *
     * @return array|iState Returns an array containing the processed metadata.
     * @throws RuntimeException Throws a RuntimeException if an unexpected item type is encountered while parsing the library.
     * @throws \App\Libs\Exceptions\Backends\InvalidArgumentException Throws an InvalidArgumentException if the item type is not supported.
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

        $url = $context->backendUrl->withPath(r('/library/metadata/{item_id}', ['item_id' => ag($item, 'ratingKey')]));
        $possibleTitlesList = ['title', 'originalTitle', 'titleSort'];

        $data = [
            'backend' => $context->backendName,
            ...$log,
        ];

        $year = (int)ag($item, ['grandParentYear', 'parentYear', 'year'], 0);
        if (0 === $year && null !== ($airDate = ag($item, 'originallyAvailableAt'))) {
            $year = (int)makeDate($airDate)->format('Y');
        }

        if ($context->trace) {
            $data['trace'] = $item;
        }

        $this->logger->debug('Processing [{backend}] {item.type} [{item.title} ({item.year})].', $data);

        $webUrl = $url->withPath('/web/index.html')->withFragment(
            r('!/server/{backend_id}/details?key={key}&context=external', [
                'backend_id' => $context->backendId,
                'key' => urlencode($url->getPath())
            ])
        );

        $metadata = [
            iState::COLUMN_ID => (int)ag($item, 'ratingKey'),
            iState::COLUMN_TYPE => ucfirst(ag($item, 'type', 'unknown')),
            iState::COLUMN_META_LIBRARY => ag($log, 'library.title'),
            'url' => (string)$url,
            'webUrl' => (string)$webUrl,
            iState::COLUMN_TITLE => ag($item, $possibleTitlesList, '??'),
            iState::COLUMN_YEAR => $year,
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

        switch (ag($item, 'type')) {
            case PlexClient::TYPE_SHOW:
                foreach (ag($item, 'Location', []) as $path) {
                    $path = ag($path, 'path');
                    $metadata['match']['paths'][] = [
                        'full' => $path,
                        'short' => basename($path),
                    ];
                }
                break;
            case PlexClient::TYPE_MOVIE:
                foreach (ag($item, 'Media', []) as $leaf) {
                    foreach (ag($leaf, 'Part', []) as $path) {
                        $path = ag($path, 'file');
                        $dir = dirname($path);

                        $metadata['match']['paths'][] = [
                            'full' => $path,
                            'short' => basename($path),
                        ];

                        if (false === str_starts_with(basename($path), basename($dir))) {
                            $metadata['match']['paths'][] = [
                                'full' => $path,
                                'short' => basename($dir),
                            ];
                        }
                    }
                }
                break;
            default:
                throw new RuntimeException(
                    r('Unexpected item type [{type}] was encountered while parsing [{backend}] library [{library}].', [
                        'backend' => $context->backendName,
                        'library' => ag($log, 'library.title', '??'),
                        'type' => ag($item, 'type')
                    ])
                );
        }

        if (null !== ($itemGuid = ag($item, 'guid')) && false === $guid->isLocal($itemGuid)) {
            $metadata[iState::COLUMN_GUIDS][] = $itemGuid;
        }

        foreach (array_column(ag($item, 'Guid', []), 'id') as $externalId) {
            $metadata[iState::COLUMN_GUIDS][] = $externalId;
        }

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $metadata[Options::RAW_RESPONSE] = $item;
        }

        return $metadata;
    }
}
