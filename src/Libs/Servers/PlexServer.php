<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use Closure;
use DateInterval;
use DateTimeInterface;
use JsonException;
use JsonMachine\Items;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use stdClass;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class PlexServer implements ServerInterface
{
    protected const GUID_MAPPER = [
        'plex' => Guid::GUID_PLEX,
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
    ];

    protected const WEBHOOK_ALLOWED_TYPES = [
        'movie',
        'episode',
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'library.new',
        'library.on.deck',
        'media.play',
        'media.stop',
        'media.resume',
        'media.pause',
        'media.scrobble',
    ];

    protected const WEBHOOK_TAINTED_EVENTS = [
        'media.play',
        'media.stop',
        'media.resume',
        'media.pause',
    ];

    protected bool $initialized = false;
    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected array $options = [];
    protected string $name = '';
    protected array $persist = [];
    protected string $cacheKey = '';
    protected array $cacheData = [];
    protected string|int|null $uuid = null;
    protected string|int|null $user = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cache
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function setUp(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        string|int|null $uuid = null,
        array $persist = [],
        array $options = []
    ): ServerInterface {
        $cloned = clone $this;

        $cloned->cacheData = [];
        $cloned->name = $name;
        $cloned->url = $url;
        $cloned->token = $token;
        $cloned->user = $userId;
        $cloned->uuid = $uuid;
        $cloned->options = $options;
        $cloned->persist = $persist;
        $cloned->cacheKey = $opts['cache_key'] ?? md5(__CLASS__ . '.' . $name . $url);

        if ($cloned->cache->has($cloned->cacheKey)) {
            $cloned->cacheData = $cloned->cache->get($cloned->cacheKey);
        }

        $cloned->initialized = true;

        return $cloned;
    }

    public function getServerUUID(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->uuid) {
            return $this->uuid;
        }

        $this->checkConfig();

        $this->logger->debug(
            sprintf('Requesting server Unique id info from %s.', $this->name),
            ['url' => $this->url->getHost()]
        );

        $url = $this->url->withPath('/');

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                sprintf(
                    'Request to %s responded with unexpected code (%d).',
                    $this->name,
                    $response->getStatusCode()
                )
            );

            return null;
        }

        $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

        $this->uuid = ag($json, 'MediaContainer.machineIdentifier', null);

        return $this->uuid;
    }

    public function getUsersList(array $opts = []): array
    {
        $this->checkConfig(checkUrl: false);

        $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost(
            'plex.tv'
        )->withPath(
            '/api/v2/home/users/'
        );

        $response = $this->http->request('GET', (string)$url, [
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $this->token,
                'X-Plex-Client-Identifier' => $this->getServerUUID(),
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                sprintf(
                    'Request to %s responded with unexpected code (%d).',
                    $this->name,
                    $response->getStatusCode()
                )
            );
        }

        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $list = [];

        foreach (ag($json, 'users', []) as $user) {
            $data = [
                'user_id' => ag($user, 'id'),
                'username' => $user['username'] ?? $user['title'] ?? $user['friendlyName'] ?? '??',
                'is_admin' => ag($user, 'admin') ? 'Yes' : 'No',
                'is_guest' => ag($user, 'guest') ? 'Yes' : 'No',
                'is_restricted' => ag($user, 'restricted') ? 'Yes' : 'No',
                'updated_at' => isset($user['updatedAt']) ? makeDate($user['updatedAt']) : 'Never',
            ];

            if (true === ($opts['tokens'] ?? false)) {
                $data['token'] = $this->getUserToken($user['uuid']);
            }

            $list[] = $data;
        }

        return $list;
    }

    public function getPersist(): array
    {
        return $this->persist;
    }

    public function addPersist(string $key, mixed $value): ServerInterface
    {
        $this->persist = ag_set($this->persist, $key, $value);
        return $this;
    }

    public function setLogger(LoggerInterface $logger): ServerInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public static function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        try {
            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === Config::get('webhook.debug', false) && !str_starts_with($userAgent, 'PlexMediaServer/')) {
                return $request;
            }

            $payload = ag($request->getParsedBody() ?? [], 'payload', null);

            if (null === $payload || null === ($json = json_decode((string)$payload, true))) {
                return $request;
            }

            $attributes = [
                'SERVER_ID' => ag($json, 'Server.uuid', ''),
                'SERVER_NAME' => ag($json, 'Server.title', ''),
                'SERVER_VERSION' => afterLast($userAgent, '/'),
                'USER_ID' => ag($json, 'Account.id', ''),
                'USER_NAME' => ag($json, 'Account.title', ''),
            ];

            foreach ($attributes as $key => $val) {
                $request = $request->withAttribute($key, $val);
            }
        } catch (Throwable $e) {
            Container::get(LoggerInterface::class)->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): StateInterface
    {
        $payload = ag($request->getParsedBody() ?? [], 'payload', null);

        if (null === $payload || null === ($json = json_decode((string)$payload, true))) {
            throw new HttpException(sprintf('%s: No payload.', afterLast(__CLASS__, '\\')), 400);
        }

        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', afterLast(__CLASS__, '\\'), $type), 200);
        }

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', afterLast(__CLASS__, '\\'), $event), 200);
        }

        $ignoreIds = null;

        if (null !== ($this->options['ignore'] ?? null)) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', $this->options['ignore']));
        }

        if (null !== $ignoreIds && in_array(ag($json, 'Metadata.librarySectionID', '???'), $ignoreIds)) {
            throw new HttpException(
                sprintf(
                    '%s: Library id \'%s\' is ignored.',
                    afterLast(__CLASS__, '\\'),
                    ag($json, 'Metadata.librarySectionID', '???')
                ), 200
            );
        }

        $meta = match ($type) {
            StateInterface::TYPE_MOVIE => [
                'via' => $this->name,
                'title' => ag($json, 'Metadata.title', ag($json, 'Metadata.originalTitle', '??')),
                'year' => ag($json, 'Metadata.year', 0000),
                'date' => makeDate(ag($json, 'Metadata.originallyAvailableAt', 'now'))->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            StateInterface::TYPE_EPISODE => [
                'via' => $this->name,
                'series' => ag($json, 'Metadata.grandparentTitle', '??'),
                'year' => ag($json, 'Metadata.year', 0000),
                'season' => ag($json, 'Metadata.parentIndex', 0),
                'episode' => ag($json, 'Metadata.index', 0),
                'title' => ag($json, 'Metadata.title', ag($json, 'Metadata.originalTitle', '??')),
                'date' => makeDate(ag($json, 'Metadata.originallyAvailableAt', 'now'))->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            default => throw new HttpException(sprintf('%s: Invalid content type.', afterLast(__CLASS__, '\\')), 400),
        };

        if (null === ($json['Metadata']['Guid'] ?? null)) {
            $json['Metadata']['Guid'] = [
                [
                    'id' => ag($json, 'Metadata.guid')
                ]
            ];
        } else {
            $json['Metadata']['Guid'][] = [
                'id' => ag($json, 'Metadata.guid')
            ];
        }

        $isWatched = (int)(bool)ag($json, 'Metadata.viewCount', 0);

        $date = time();

        if (!$this->hasSupportedIds($json['Metadata']['Guid'] ?? [])) {
            throw new HttpException(
                sprintf(
                    '%s: No supported GUID was given. [%s]',
                    afterLast(__CLASS__, '\\'),
                    arrayToString($json['Metadata']['Guid'] ?? [])
                ),
                400
            );
        }

        $guids = $this->getGuids($type, $json['Metadata']['Guid'] ?? []);

        foreach (Guid::fromArray($guids)->getPointers() as $guid) {
            $this->cacheData[$guid] = ag($json, 'Metadata.guid');
        }

        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => $isWatched,
            'meta' => $meta,
            ...$guids
        ];

        if (true === Config::get('webhook.debug')) {
            saveWebhookPayload($request, "{$this->name}.{$event}", $json + ['entity' => $row]);
        }

        return Container::get(StateInterface::class)::fromArray($row)->setIsTainted(
            in_array($event, self::WEBHOOK_TAINTED_EVENTS)
        );
    }

    private function getHeaders(): array
    {
        $opts = [
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $this->token,
            ],
        ];

        return array_replace_recursive($opts, $this->options['client'] ?? []);
    }

    protected function getLibraries(Closure $ok, Closure $error): array
    {
        $this->checkConfig();

        try {
            $this->logger->debug(
                sprintf('Requesting libraries From %s.', $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath('/library/sections');

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        'Request to %s responded with unexpected code (%d).',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                Data::add($this->name, 'no_import_update', true);
                return [];
            }

            $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->notice(sprintf('No libraries found at %s.', $this->name));
                Data::add($this->name, 'no_import_update', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(
                sprintf('Request to %s failed. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        $ignoreIds = null;

        if (null !== ($this->options['ignore'] ?? null)) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', $this->options['ignore']));
        }

        $promises = [];
        $ignored = $unsupported = 0;

        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');
            $title = ag($section, 'title', '???');

            if ('movie' !== $type && 'show' !== $type) {
                $unsupported++;
                $this->logger->debug(sprintf('Skipping %s library - %s. Not supported type.', $this->name, $title));
                continue;
            }

            $type = $type === 'movie' ? StateInterface::TYPE_MOVIE : StateInterface::TYPE_EPISODE;
            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            if (null !== $ignoreIds && in_array($key, $ignoreIds)) {
                $ignored++;
                $this->logger->notice(
                    sprintf('Skipping %s library - %s. Ignored by user config option.', $this->name, $cName)
                );
                continue;
            }

            $url = $this->url->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                http_build_query(
                    [
                        'type' => 'movie' === $type ? 1 : 4,
                        'sort' => 'addedAt:asc',
                        'includeGuids' => 1,
                    ]
                )
            );

            $this->logger->debug(sprintf('Requesting %s - %s library content.', $this->name, $cName), ['url' => $url]);

            try {
                $promises[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'ok' => $ok($cName, $type, $url),
                            'error' => $error($cName, $type, $url),
                        ]
                    ])
                );
            } catch (ExceptionInterface $e) {
                $this->logger->error(
                    sprintf('Request to %s library - %s failed. Reason: %s', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
                continue;
            }
        }

        if (0 === count($promises)) {
            $this->logger->notice(
                sprintf(
                    'No requests were made to any of %s libraries. (total: %d, ignored: %d, Unsupported: %d).',
                    $this->name,
                    count($listDirs),
                    $ignored,
                    $unsupported
                )
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        return $promises;
    }

    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            function (string $cName, string $type) use ($after, $mapper) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type, $after) {
                    try {
                        if (200 !== $response->getStatusCode()) {
                            $this->logger->error(
                                sprintf(
                                    'Request to %s - %s responded with (%d) unexpected code.',
                                    $this->name,
                                    $cName,
                                    $response->getStatusCode()
                                )
                            );
                            return;
                        }

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/MediaContainer/Metadata',
                            ],
                        );

                        $this->logger->info(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));

                        foreach ($it as $entity) {
                            $this->processImport($mapper, $type, $cName, $entity, $after);
                        }

                        $this->logger->info(
                            sprintf(
                                'Finished Parsing %s - %s (%d objects) response.',
                                $this->name,
                                $cName,
                                Data::get("{$this->name}.{$cName}_total")
                            )
                        );
                    } catch (JsonException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to decode %s - %s - response. Reason: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                            ],
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            }
        );
    }

    public function push(array $entities, DateTimeInterface|null $after = null): array
    {
        $this->checkConfig();

        $requests = [];

        foreach ($entities as &$entity) {
            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    $entity = null;
                    continue;
                }
            }

            if (null !== $entity->guid_plex) {
                continue;
            }

            foreach ($entity->getPointers() as $guid) {
                if (null === ($this->cacheData[$guid] ?? null)) {
                    continue;
                }
                $entity->guid_plex = $this->cacheData[$guid];
                break;
            }

            if (null === $entity->guid_plex) {
                $entity = null;
            }
        }

        unset($entity);

        foreach ($entities as $entity) {
            if (null === $entity) {
                continue;
            }

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$this->url->withPath('/library/all')->withQuery(
                        http_build_query(
                            [
                                'guid' => 'plex://' . $entity->guid_plex,
                                'includeGuids' => 1,
                            ]
                        )
                    ),
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'state' => &$entity,
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            }
        }

        $stateRequests = [];

        foreach ($requests as $response) {
            try {
                $content = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

                $json = ag($content, 'MediaContainer.Metadata', [])[0] ?? [];

                $state = $response->getInfo('user_data')['state'] ?? null;

                if (null === $state) {
                    $this->logger->error(
                        sprintf(
                            'Request failed with code \'%d\'.',
                            $response->getStatusCode(),
                        ),
                        $response->getHeaders()
                    );
                    continue;
                }

                assert($state instanceof StateInterface);

                if (StateInterface::TYPE_MOVIE === $state->type) {
                    $iName = sprintf(
                        '%s - [%s (%d)]',
                        $this->name,
                        $state->meta['title'] ?? '??',
                        $state->meta['year'] ?? 0000,
                    );
                } else {
                    $iName = trim(
                        sprintf(
                            '%s - [%s - (%dx%d) - %s]',
                            $this->name,
                            $state->meta['series'] ?? '??',
                            $state->meta['season'] ?? 0,
                            $state->meta['episode'] ?? 0,
                            $state->meta['title'] ?? '??',
                        )
                    );
                }

                if (empty($json)) {
                    $this->logger->notice(sprintf('Ignoring %s. does not exists.', $iName));
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'viewCount', 0);

                if ($state->watched === $isWatched) {
                    $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                    continue;
                }

                if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                    $date = max(
                        (int)ag($json, 'updatedAt', 0),
                        (int)ag($json, 'lastViewedAt', 0),
                        (int)ag($json, 'addedAt', 0)
                    );

                    if (0 === $date) {
                        $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                        continue;
                    }

                    if ($date >= $state->updated) {
                        $this->logger->debug(sprintf('Ignoring %s. Date is newer then what in db.', $iName));
                        continue;
                    }
                }

                $stateRequests[] = $this->http->request(
                    'GET',
                    (string)$this->url->withPath((1 === $state->watched ? '/:/scrobble' : '/:/unscrobble'))
                        ->withQuery(
                            http_build_query(
                                [
                                    'identifier' => 'com.plexapp.plugins.library',
                                    'key' => ag($json, 'ratingKey'),
                                ]
                            )
                        ),
                    array_replace_recursive(
                        $this->getHeaders(),
                        [
                            'user_data' => [
                                'state' => 1 === $state->watched ? 'Watched' : 'Unwatched',
                                'itemName' => $iName,
                            ],
                        ]
                    )
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            }
        }

        unset($requests);

        return $stateRequests;
    }

    public function export(ExportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            function (string $cName, string $type) use ($mapper, $after) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type, $after) {
                    try {
                        if (200 !== $response->getStatusCode()) {
                            $this->logger->error(
                                sprintf(
                                    'Request to %s - %s responded with unexpected http status code (%d).',
                                    $this->name,
                                    $cName,
                                    $response->getStatusCode()
                                )
                            );
                            return;
                        }

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/MediaContainer/Metadata',
                            ],
                        );

                        $this->logger->info(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));

                        foreach ($it as $entity) {
                            $this->processExport($mapper, $type, $cName, $entity, $after);
                        }

                        $this->logger->info(
                            sprintf(
                                'Finished Parsing %s - %s (%d objects) response.',
                                $this->name,
                                $cName,
                                Data::get("{$this->name}.{$type}_total")
                            )
                        );
                    } catch (JsonException $e) {
                        $this->logger->error(
                            sprintf(
                                'Failed to decode %s - %s - response. Reason: \'%s\'.',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                            ]
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            }
        );
    }

    protected function processExport(
        ExportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        Data::increment($this->name, $type . '_total');

        try {
            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - %s - [%s (%d)]',
                    $this->name,
                    $library,
                    $item->title ?? $item->originalTitle ?? '??',
                    $item->year ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - %s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        $item->parentIndex ?? 0,
                        $item->index ?? 0,
                        $item->title ?? $item->originalTitle ?? '',
                    )
                );
            }

            if (null === ($item->Guid ?? null)) {
                $item->Guid = [['id' => $item->guid]];
            } else {
                $item->Guid[] = ['id' => $item->guid];
            }

            if (!$this->hasSupportedIds($item->Guid)) {
                $this->logger->debug(sprintf('Ignoring %s. No supported guid.', $iName), $item->Guid ?? []);
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            $guids = $this->getGuids($type, $item->Guid ?? []);

            foreach (Guid::fromArray($guids)->getPointers() as $guid) {
                $this->cacheData[$guid] = $item->guid;
            }

            $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

            if (0 === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            if (null !== $after && $date >= $after->getTimestamp()) {
                $this->logger->debug(sprintf('Ignoring %s. date is equal or newer than lastSync.', $iName));
                Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                return;
            }

            $isWatched = (int)(bool)($item->viewCount ?? false);

            if (null === ($entity = $mapper->findByIds($guids))) {
                $this->logger->debug(sprintf('Ignoring %s. Not found in db.', $iName), $item->ProviderIds ?? []);
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if ($date >= $entity->updated) {
                    $this->logger->debug(sprintf('Ignoring %s. Date is newer then what in db.', $iName));
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }

            if ($isWatched === $entity->watched) {
                $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            $this->logger->debug(sprintf('Queuing %s.', $iName), ['url' => $this->url]);

            $mapper->queue(
                $this->http->request(
                    'GET',
                    (string)$this->url->withPath('/:' . (1 === $entity->watched ? '/scrobble' : '/unscrobble'))
                        ->withQuery(
                            http_build_query(
                                [
                                    'identifier' => 'com.plexapp.plugins.library',
                                    'key' => $item->ratingKey,
                                ]
                            )
                        ),
                    array_replace_recursive(
                        $this->getHeaders(),
                        [
                            'user_data' => [
                                'state' => 1 === $entity->watched ? 'Watched' : 'Unwatched',
                                'itemName' => $iName,
                            ],
                        ]
                    )
                )
            );
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    protected function processImport(
        ImportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        try {
            Data::increment($this->name, $library . '_total');
            Data::increment($this->name, $type . '_total');

            if (StateInterface::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - %s - [%s (%d)]',
                    $this->name,
                    $library,
                    $item->title ?? $item->originalTitle ?? '??',
                    $item->year ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - %s - [%s - (%dx%d) - %s]',
                        $this->name,
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        $item->parentIndex ?? 0,
                        $item->index ?? 0,
                        $item->title ?? $item->originalTitle ?? '',
                    )
                );
            }

            if (null === ($item->Guid ?? null)) {
                $item->Guid = [['id' => $item->guid]];
            } else {
                $item->Guid[] = ['id' => $item->guid];
            }

            if (!$this->hasSupportedIds($item->Guid)) {
                $this->logger->debug(sprintf('Ignoring %s. No valid GUIDs.', $iName), $item->Guid ?? []);
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            $guids = $this->getGuids($type, $item->Guid ?? []);

            foreach (Guid::fromArray($guids)->getPointers() as $guid) {
                $this->cacheData[$guid] = $item->guid;
            }

            $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

            if (0 === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            if (StateInterface::TYPE_MOVIE === $type) {
                $meta = [
                    'via' => $this->name,
                    'title' => $item->title ?? $item->originalTitle ?? '??',
                    'year' => $item->year ?? 0000,
                    'date' => makeDate($item->originallyAvailableAt ?? 'now')->format('Y-m-d'),
                ];
            } else {
                $meta = [
                    'via' => $this->name,
                    'series' => $item->grandparentTitle ?? '??',
                    'year' => $item->year ?? 0000,
                    'season' => $item->parentIndex ?? 0,
                    'episode' => $item->index ?? 0,
                    'title' => $item->title ?? $item->originalTitle ?? '??',
                    'date' => makeDate($item->originallyAvailableAt ?? 'now')->format('Y-m-d'),
                ];
            }

            $row = [
                'type' => $type,
                'updated' => $date,
                'watched' => (int)(bool)($item->viewCount ?? false),
                'meta' => $meta,
                ...$guids
            ];

            $mapper->add($this->name, $iName, Container::get(StateInterface::class)::fromArray($row), [
                'after' => $after,
                self::OPT_IMPORT_UNWATCHED => (bool)($this->options[self::OPT_IMPORT_UNWATCHED] ?? false),
            ]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function getGuids(string $type, array $guids): array
    {
        $guid = [];

        foreach ($guids as $_id) {
            $val = is_object($_id) ? $_id->id : $_id['id'];

            if (empty($val)) {
                continue;
            }

            [$key, $value] = explode('://', $val);
            $key = strtolower($key);

            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            if ($key !== 'plex') {
                $value = $type . '/' . $value;
            }

            if ('string' !== Guid::SUPPORTED[self::GUID_MAPPER[$key]]) {
                settype($value, Guid::SUPPORTED[self::GUID_MAPPER[$key]]);
            }

            $guid[self::GUID_MAPPER[$key]] = $value;
        }

        return $guid;
    }

    protected function hasSupportedIds(array $guids): bool
    {
        foreach ($guids as $_id) {
            $val = is_object($_id) ? $_id->id : $_id['id'];

            if (empty($val)) {
                continue;
            }

            [$key, $value] = explode('://', $val);
            $key = strtolower($key);

            if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __destruct()
    {
        if (!empty($this->cacheKey) && !empty($this->cacheData) && true === $this->initialized) {
            $this->cache->set($this->cacheKey, $this->cacheData, new DateInterval('P1Y'));
        }
    }

    private function checkConfig(bool $checkUrl = true, bool $checkToken = true): void
    {
        if (true === $checkUrl && !($this->url instanceof UriInterface)) {
            throw new RuntimeException(afterLast(__CLASS__, '\\') . ': No host was set.');
        }

        if (true === $checkToken && null === $this->token) {
            throw new RuntimeException(afterLast(__CLASS__, '\\') . ': No token was set.');
        }
    }

    private function getUserToken(int|string $userId): int|string|null
    {
        try {
            $uuid = $this->getServerUUID();

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost(
                'plex.tv'
            )->withPath(sprintf('/api/v2/home/users/%s/switch', $userId));

            $this->logger->debug(
                sprintf('Requesting temp token for user id %s from %s.', $userId, $this->name),
                ['url' => $url->getHost() . $url->getPath()]
            );

            $response = $this->http->request('POST', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $this->token,
                    'X-Plex-Client-Identifier' => $uuid,
                ],
            ]);

            if (201 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        'Request to %s responded with unexpected code (%d).',
                        $this->name,
                        $response->getStatusCode()
                    )
                );

                return null;
            }

            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $tempToken = ag($json, 'authToken', null);

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost(
                'plex.tv'
            )->withPath('/api/v2/resources')
                ->withQuery(
                    http_build_query(
                        [
                            'includeIPv6' => 1,
                            'includeHttps' => 1,
                            'includeRelay' => 1,
                        ]
                    )
                );

            $this->logger->debug(
                sprintf('Requesting real server token for user id %s from %s.', $userId, $this->name),
                ['url' => $url->getHost() . $url->getPath()]
            );

            $response = $this->http->request('GET', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $tempToken,
                    'X-Plex-Client-Identifier' => $uuid,
                ],
            ]);

            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

            foreach ($json ?? [] as $server) {
                if (ag($server, 'clientIdentifier') !== $uuid) {
                    continue;
                }
                return ag($server, 'accessToken');
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }
}
