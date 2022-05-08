<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use JsonException;
use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
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

    protected const SUPPORTED_LEGACY_AGENTS = [
        'com.plexapp.agents.imdb',
        'com.plexapp.agents.tmdb',
        'com.plexapp.agents.themoviedb',
        'com.plexapp.agents.xbmcnfo',
    ];

    protected const PARENT_SUPPORTED_LEGACY_AGENTS = [
        'com.plexapp.agents.xbmcnfotv',
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

    protected array $showInfo = [];
    protected array $cacheShow = [];
    protected string $cacheShowKey = '';

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
        $cloned->cacheShowKey = $cloned->cacheKey . '_show';

        if ($cloned->cache->has($cloned->cacheKey)) {
            $cloned->cacheData = $cloned->cache->get($cloned->cacheKey);
        }

        if ($cloned->cache->has($cloned->cacheShowKey)) {
            $cloned->cacheShow = $cloned->cache->get($cloned->cacheShowKey);
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

        $adminsCount = 0;

        $users = ag($json, 'users', []);

        foreach ($users as $user) {
            if (true === (bool)ag($user, 'admin')) {
                $adminsCount++;
            }
        }

        foreach ($users as $user) {
            $data = [
                'user_id' => ag($user, 'admin') && $adminsCount <= 1 ? 1 : ag($user, 'id'),
                'username' => $user['username'] ?? $user['title'] ?? $user['friendlyName'] ?? $user['email'] ?? '??',
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

        unset($json, $users);

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
                'WH_EVENT' => ag($json, 'event', 'not_set'),
                'WH_TYPE' => ag($json, 'Metadata.type', 'not_set'),
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

        $item = ag($json, 'Metadata', []);
        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', afterLast(__CLASS__, '\\'), $type), 200);
        }

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', afterLast(__CLASS__, '\\'), $event), 200);
        }

        $isTainted = in_array($event, self::WEBHOOK_TAINTED_EVENTS);

        $ignoreIds = null;

        if (null !== ($this->options['ignore'] ?? null)) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$this->options['ignore']));
        }

        if (null !== $ignoreIds && in_array(ag($item, 'librarySectionID', '???'), $ignoreIds)) {
            throw new HttpException(
                sprintf(
                    '%s: Library id \'%s\' is ignored.',
                    afterLast(__CLASS__, '\\'),
                    ag($item, 'librarySectionID', '???')
                ), 200
            );
        }

        $meta = match ($type) {
            StateInterface::TYPE_MOVIE => [
                'via' => $this->name,
                'title' => ag($item, 'title', ag($item, 'originalTitle', '??')),
                'year' => ag($item, 'year', 0000),
                'date' => makeDate(ag($item, 'originallyAvailableAt', 'now'))->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            StateInterface::TYPE_EPISODE => [
                'via' => $this->name,
                'series' => ag($item, 'grandparentTitle', '??'),
                'year' => ag($item, 'year', 0000),
                'season' => ag($item, 'parentIndex', 0),
                'episode' => ag($item, 'index', 0),
                'title' => ag($item, 'title', ag($item, 'originalTitle', '??')),
                'date' => makeDate(ag($item, 'originallyAvailableAt', 'now'))->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            default => throw new HttpException(sprintf('%s: Invalid content type.', afterLast(__CLASS__, '\\')), 400),
        };

        if (null === ag($item, 'Guid', null)) {
            $item['Guid'] = [['id' => ag($item, 'guid')]];
        } else {
            $item['Guid'][] = ['id' => ag($item, 'guid')];
        }

        if (StateInterface::TYPE_EPISODE === $type) {
            $parentId = ag($item, 'grandparentRatingKey', fn() => ag($item, 'parentRatingKey'));
            $meta['parent'] = null !== $parentId ? $this->getEpisodeParent($parentId) : [];
        }

        $row = [
            'type' => $type,
            'updated' => time(),
            'watched' => (int)(bool)ag($item, 'viewCount', 0),
            'meta' => $meta,
            ...$this->getGuids(ag($item, 'Guid', []), isParent: false)
        ];

        $entity = Container::get(StateInterface::class)::fromArray($row)->setIsTainted($isTainted);

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            throw new HttpException(
                sprintf(
                    '%s: No supported GUID was given. [%s]',
                    afterLast(__CLASS__, '\\'),
                    arrayToString(
                        [
                            'guids' => !empty($item['Guid']) ? $item['Guid'] : 'None',
                            'rGuids' => $entity->hasRelativeGuid() ? $entity->getRelativeGuids() : 'None',
                        ]
                    )
                ), 400
            );
        }

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = ag($item, 'guid');
        }

        if (false !== $isTainted && (true === Config::get('webhook.debug') || null !== ag(
                    $request->getQueryParams(),
                    'debug'
                ))) {
            saveWebhookPayload($this->name . '.' . $event, $request, [
                'entity' => $entity->getAll(),
                'payload' => $json,
            ]);
        }

        return $entity;
    }

    protected function getEpisodeParent(int|string $id): array
    {
        if (array_key_exists($id, $this->cacheShow)) {
            return $this->cacheShow[$id];
        }

        try {
            $response = $this->http->request(
                'GET',
                (string)$this->url->withPath('/library/metadata/' . $id),
                $this->getHeaders()
            );

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

            $json = ag($json, 'MediaContainer.Metadata')[0] ?? [];

            if (null === ($type = ag($json, 'type'))) {
                return [];
            }

            if ('show' !== strtolower($type)) {
                return [];
            }

            if (null === ($json['Guid'] ?? null)) {
                $json['Guid'] = [['id' => $json['guid']]];
            } else {
                $json['Guid'][] = ['id' => $json['guid']];
            }

            if (!$this->hasSupportedGuids($json['Guid'], true)) {
                $this->cacheShow[$id] = [];
                return $this->cacheShow[$id];
            }

            $guids = [];

            foreach (Guid::fromArray($this->getGuids($json['Guid'], isParent: true))->getPointers() as $guid) {
                [$type, $id] = explode('://', $guid);
                $guids[$type] = $id;
            }

            $this->cacheShow[$id] = $guids;

            return $this->cacheShow[$id];
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            return [];
        } catch (Exception $e) {
            $this->logger->error(
                sprintf('ERROR: %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );
            return [];
        }
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

    protected function getLibraries(Closure $ok, Closure $error, bool $includeParent = false): array
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
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$this->options['ignore']));
        }

        $promises = [];
        $ignored = $unsupported = 0;

        if (true === $includeParent) {
            foreach ($listDirs as $section) {
                $key = (int)ag($section, 'key');
                $title = ag($section, 'title', '???');

                if ('show' !== ag($section, 'type', 'unknown')) {
                    continue;
                }

                $cName = sprintf('(%s) - (%s:%s)', $title, 'show', $key);

                if (null !== $ignoreIds && in_array($key, $ignoreIds)) {
                    continue;
                }

                $url = $this->url->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                    http_build_query(
                        [
                            'type' => 2,
                            'sort' => 'addedAt:asc',
                            'includeGuids' => 1,
                        ]
                    )
                );

                $this->logger->debug(
                    sprintf('Requesting %s - %s library parents content.', $this->name, $cName),
                    ['url' => $url]
                );

                try {
                    $promises[] = $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'ok' => $ok($cName, 'show', $url),
                                'error' => $error($cName, 'show', $url),
                            ]
                        ])
                    );
                } catch (ExceptionInterface $e) {
                    $this->logger->error(
                        sprintf(
                            'Request to %s library - %s parents failed. Reason: %s',
                            $this->name,
                            $cName,
                            $e->getMessage()
                        ),
                        [
                            'url' => $url,
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]
                    );
                    continue;
                }
            }
        }

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

    public function search(string $query, int $limit = 25): array
    {
        $this->checkConfig();

        try {
            $this->logger->debug(
                sprintf('Search for \'%s\' in %s.', $query, $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath('/hubs/search')->withQuery(
                http_build_query(
                    [
                        'query' => $query,
                        'limit' => $limit,
                        'includeGuids' => 1,
                        'includeExternalMedia' => 0,
                        'includeCollections' => 0,
                    ]
                )
            );

            $this->logger->debug('Request', ['url' => $url]);

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

            $list = [];

            $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

            foreach (ag($json, 'MediaContainer.Hub', []) as $item) {
                $type = ag($item, 'type');

                if ('show' !== $type && 'movie' !== $type) {
                    continue;
                }

                foreach (ag($item, 'Metadata', []) as $subItem) {
                    $list[] = $subItem;
                }
            }

            return $list;
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function listLibraries(): array
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
                return [];
            }

            $json = json_decode($response->getContent(false), true, flags: JSON_THROW_ON_ERROR);

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->error(sprintf('No libraries found at %s.', $this->name));
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
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            return [];
        }

        $ignoreIds = null;

        if (null !== ($this->options['ignore'] ?? null)) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$this->options['ignore']));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');
            $title = ag($section, 'title', '???');
            $isIgnored = null !== $ignoreIds && in_array($key, $ignoreIds);

            $list[] = [
                'ID' => $key,
                'Title' => $title,
                'Type' => $type,
                'Ignored' => $isIgnored ? 'Yes' : 'No',
                'Supported' => 'movie' !== $type && 'show' !== $type ? 'No' : 'Yes',
            ];
        }

        return $list;
    }

    public function pull(ImportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (string $cName, string $type) use ($after, $mapper) {
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

                        // -- sandbox external library code to prevent complete failure when error occurs.
                        try {
                            $it = Items::fromIterable(
                                httpClientChunks($this->http->stream($response)),
                                [
                                    'pointer' => '/MediaContainer/Metadata',
                                    'decoder' => new ErrorWrappingDecoder(
                                        new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                    )
                                ]
                            );

                            $this->logger->info(sprintf('Parsing %s - %s response.', $this->name, $cName));

                            foreach ($it as $entity) {
                                if ($entity instanceof DecodingError) {
                                    $this->logger->debug(
                                        sprintf('Failed to decode one result of %s - %s response.', $this->name, $cName)
                                    );
                                    continue;
                                }
                                $this->processImport($mapper, $type, $cName, $entity, $after);
                            }
                        } catch (PathNotFoundException $e) {
                            $this->logger->error(
                                sprintf(
                                    'Failed to find media items path in %s - %s - response. Most likely empty library?',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        } catch (Throwable $e) {
                            $this->logger->error(
                                sprintf(
                                    'Unable to parse %s - %s response.',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
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
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            },
            includeParent: true
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

            $entity->plex_id = null;

            if (null !== ($entity->guids[Guid::GUID_PLEX] ?? null)) {
                $entity->plex_id = 'plex://' . $entity->guids[Guid::GUID_PLEX];
                continue;
            }

            foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
                if (null === ($this->cacheData[$guid] ?? null)) {
                    continue;
                }
                $entity->plex_id = $this->cacheData[$guid];
                break;
            }
        }

        unset($entity);

        foreach ($entities as $entity) {
            if (null === $entity) {
                continue;
            }

            if ($entity->isMovie()) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $this->name,
                    ag($entity->meta, 'title', '??'),
                    ag($entity->meta, 'year', 0000),
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%dx%d)]',
                        $this->name,
                        ag($entity->meta, 'series', '??'),
                        ag($entity->meta, 'season', 0),
                        ag($entity->meta, 'episode', 0),
                    )
                );
            }

            if (null === ($entity->plex_id ?? null)) {
                $this->logger->notice(sprintf('Ignoring %s. Not found in \'%s\' local cache.', $iName, $this->name));
                continue;
            }

            try {
                $requests[] = $this->http->request(
                    'GET',
                    (string)$this->url->withPath('/library/all')->withQuery(
                        http_build_query(
                            [
                                'guid' => $entity->plex_id,
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
                            '%s - [%s - (%dx%d)]',
                            $this->name,
                            $state->meta['series'] ?? '??',
                            $state->meta['season'] ?? 0,
                            $state->meta['episode'] ?? 0,
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
            ok: function (string $cName, string $type) use ($mapper, $after) {
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

                        try {
                            $it = Items::fromIterable(
                                httpClientChunks($this->http->stream($response)),
                                [
                                    'pointer' => '/MediaContainer/Metadata',
                                    'decoder' => new ErrorWrappingDecoder(
                                        new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                    )
                                ]
                            );

                            $this->logger->info(sprintf('Parsing %s - %s response.', $this->name, $cName));

                            foreach ($it as $entity) {
                                if ($entity instanceof DecodingError) {
                                    $this->logger->debug(
                                        sprintf('Failed to decode one result of %s - %s response.', $this->name, $cName)
                                    );
                                    continue;
                                }
                                $this->processExport($mapper, $type, $cName, $entity, $after);
                            }
                        } catch (PathNotFoundException $e) {
                            $this->logger->error(
                                sprintf(
                                    'Failed to find media items path in %s - %s - response. Most likely empty library?',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        } catch (Throwable $e) {
                            $this->logger->error(
                                sprintf(
                                    'Unable to parse %s - %s response.',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
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
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            },
            includeParent: false
        );
    }

    public function cache(): array
    {
        return $this->getLibraries(
            ok: function (string $cName, string $type) {
                return function (ResponseInterface $response) use ($cName, $type) {
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

                        try {
                            $it = Items::fromIterable(
                                httpClientChunks($this->http->stream($response)),
                                [
                                    'pointer' => '/MediaContainer/Metadata',
                                    'decoder' => new ErrorWrappingDecoder(
                                        new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                    )
                                ]
                            );

                            $this->logger->info(sprintf('Parsing %s - %s response.', $this->name, $cName));

                            foreach ($it as $entity) {
                                if ($entity instanceof DecodingError) {
                                    $this->logger->debug(
                                        sprintf('Failed to decode one result of %s - %s response.', $this->name, $cName)
                                    );
                                    continue;
                                }

                                $this->processForCache($entity, $type, $cName);
                            }
                        } catch (PathNotFoundException $e) {
                            $this->logger->error(
                                sprintf(
                                    'Failed to find media items path in %s - %s - response. Most likely empty library?',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        } catch (Throwable $e) {
                            $this->logger->error(
                                sprintf(
                                    'Unable to parse %s - %s response.',
                                    $this->name,
                                    $cName,
                                ),
                                [
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'kind' => get_class($e),
                                    'error' => $e->getMessage(),
                                ],
                            );
                            return;
                        }

                        $this->logger->info(sprintf('Finished Parsing %s - %s response.', $this->name, $cName));
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
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    [
                        'url' => $url,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]
                );
            },
            includeParent: true
        );
    }

    protected function processExport(
        ExportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        try {
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
                        '%s - %s - [%s - (%dx%d)]',
                        $this->name,
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        $item->parentIndex ?? 0,
                        $item->index ?? 0,
                    )
                );
            }

            $date = $item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? null;

            if (null === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity($item, $type);

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $guids = $item->Guid ?? [];
                $this->logger->debug(
                    sprintf('Ignoring %s. No valid/supported guids.', $iName),
                    [
                        'guids' => !empty($guids) ? $guids : 'None',
                        'rGuids' => $rItem->hasRelativeGuid() ? $rItem->getRelativeGuids() : 'None',
                    ]
                );
                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if (null !== $after && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(sprintf('Ignoring %s. date is equal or newer than lastSync.', $iName));
                    Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $guids = $item->Guid ?? [];
                $this->logger->debug(
                    sprintf(
                        'Ignoring %s. [State: %s] - Not found in db.',
                        $iName,
                        $rItem->watched ? 'Played' : 'Unplayed'
                    ),
                    [
                        'guids' => !empty($guids) ? $guids : 'None',
                        'rGuids' => $rItem->hasRelativeGuid() ? $rItem->getRelativeGuids() : 'None',
                    ]
                );
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                $this->logger->debug(sprintf('Ignoring %s. State is equal to db state.', $iName), [
                    'State' => $entity->watched ? 'Played' : 'Unplayed'
                ]);
                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if ($rItem->updated >= $entity->updated) {
                    $this->logger->debug(sprintf('Ignoring %s. Date is newer or equal to db entry.', $iName), [
                        'db' => makeDate($rItem->updated),
                        'server' => makeDate($entity->updated),
                    ]);
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }

            $this->logger->info(sprintf('Queuing %s.', $iName), [
                'State' => [
                    'db' => $entity->watched ? 'Played' : 'Unplayed',
                    'server' => $rItem->watched ? 'Played' : 'Unplayed'
                ],
            ]);

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
                                'state' => 1 === $entity->watched ? 'Played' : 'Unplayed',
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

    protected function processShow(StdClass $item, string $library): void
    {
        if (null === ($item->Guid ?? null)) {
            $item->Guid = [['id' => $item->guid]];
        } else {
            $item->Guid[] = ['id' => $item->guid];
        }

        if (!$this->hasSupportedGuids($item->Guid, true)) {
            $iName = sprintf(
                '%s - %s - [%s (%d)]',
                $this->name,
                $library,
                $item->title ?? $item->originalTitle ?? '??',
                $item->year ?? 0000
            );
            $message = sprintf('Ignoring %s. No valid/supported GUIDs.', $iName);
            if (empty($item->Guid)) {
                $message .= ' Most likely unmatched TV show.';
            }
            $this->logger->info($message, [
                'guids' => empty($item->Guid) ? 'None' : $item->Guid
            ]);
            return;
        }

        $guids = [];

        foreach (Guid::fromArray($this->getGuids($item->Guid, isParent: true))->getPointers() as $guid) {
            [$type, $id] = explode('://', $guid);
            $guids[$type] = $id;
        }

        $this->showInfo[$item->ratingKey] = $guids;
    }

    protected function processImport(
        ImportInterface $mapper,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        try {
            if ('show' === $type) {
                $this->processShow($item, $library);
                return;
            }

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
                        '%s - %s - [%s - (%sx%s)]',
                        $this->name,
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        str_pad((string)($item->parentIndex ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($item->index ?? 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

            if (0 === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity($item, $type);

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                if (null === ($item->Guid ?? null)) {
                    $item->Guid = [['id' => $item->guid]];
                } else {
                    $item->Guid[] = ['id' => $item->guid];
                }

                if (true === Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->name . '.' . $item->ratingKey . '.json';

                    if (!file_exists($name)) {
                        file_put_contents($name, json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }

                $message = sprintf('Ignoring %s. No valid/supported GUIDs.', $iName);

                if (empty($item->Guid)) {
                    $message .= ' Most likely unmatched item.';
                }

                $this->logger->info($message, ['guids' => empty($item->Guid) ? 'None' : $item->Guid]);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            $mapper->add($this->name, $iName, $entity, ['after' => $after]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function processForCache(StdClass $item, string $type, string $library): void
    {
        try {
            if ('show' === $type) {
                $this->processShow($item, $library);
                return;
            }

            $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

            if (0 === $date) {
                return;
            }

            $this->createEntity($item, $type);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    protected function getGuids(array $guids, bool $isParent = false): array
    {
        $guid = [];

        foreach ($guids as $_id) {
            $val = is_object($_id) ? $_id->id : $_id['id'];

            if (empty($val)) {
                continue;
            }

            if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                $val = $this->parseLegacyAgent($val, $isParent);
            }

            [$key, $value] = explode('://', $val);
            $key = strtolower($key);

            if (null === (self::GUID_MAPPER[$key] ?? null) || empty($value)) {
                continue;
            }

            if (null !== ($guid[self::GUID_MAPPER[$key]] ?? null) && ctype_digit($val)) {
                if ((int)$guid[self::GUID_MAPPER[$key]] > (int)$val) {
                    continue;
                }
            }

            $guid[self::GUID_MAPPER[$key]] = $value;
        }

        ksort($guid);

        return $guid;
    }

    protected function hasSupportedGuids(array $guids, bool $isParent = false): bool
    {
        foreach ($guids as $_id) {
            $val = is_object($_id) ? $_id->id : $_id['id'];

            if (empty($val)) {
                continue;
            }

            if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                $val = $this->parseLegacyAgent($val, $isParent);
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

        if (!empty($this->cacheShowKey) && !empty($this->cacheShow) && true === $this->initialized) {
            $this->cache->set($this->cacheShowKey, $this->cacheShow, new DateInterval('PT30M'));
        }
    }

    /**
     * Parse Plex agents identifier.
     *
     * @param string $agent
     * @param bool $isParent
     *
     * @return string
     * @see SUPPORTED_LEGACY_AGENTS
     */
    private function parseLegacyAgent(string $agent, bool $isParent = false): string
    {
        try {
            $supported = self::SUPPORTED_LEGACY_AGENTS;

            if (true === $isParent) {
                $supported = array_merge_recursive($supported, self::PARENT_SUPPORTED_LEGACY_AGENTS);
            }

            if (false === in_array(before($agent, '://'), $supported)) {
                return $agent;
            }

            $replacer = [
                'com.plexapp.agents.themoviedb://' => 'com.plexapp.agents.tmdb://',
                'com.plexapp.agents.xbmcnfo://' => 'com.plexapp.agents.imdb://',
            ];

            if (true === $isParent) {
                $replacer += [
                    'com.plexapp.agents.xbmcnfotv://' => 'com.plexapp.agents.tvdb://',
                ];
            }

            $agent = str_replace(array_keys($replacer), array_values($replacer), $agent);

            $id = afterLast($agent, 'agents.');
            $agentGuid = explode('://', $id);
            $agent = $agentGuid[0];
            $guid = explode('/', $agentGuid[1])[0];

            return $agent . '://' . before($guid, '?');
        } catch (Throwable $e) {
            $this->logger->error('Unable to match Legacy plex agent.', ['guid' => $agent, 'e' => $e->getMessage()]);
            return $agent;
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

    private function createEntity(StdClass $item, string $type): StateEntity
    {
        if (null === ($item->Guid ?? null)) {
            $item->Guid = [['id' => $item->guid]];
        } else {
            $item->Guid[] = ['id' => $item->guid];
        }

        $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

        /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => (int)(bool)($item->viewCount ?? false),
            'via' => $this->name,
            'title' => '??',
            'year' => (int)($item->grandParentYear ?? $item->parentYear ?? $item->year ?? 0000),
            'season' => null,
            'episode' => null,
            'parent' => [],
            'guids' => $this->getGuids($item->Guid ?? [], isParent: false),
            'extra' => [
                'date' => makeDate($item->originallyAvailableAt ?? 'now')->format('Y-m-d'),
            ],
        ];

        if (StateInterface::TYPE_MOVIE === $type) {
            $row['title'] = $item->title ?? $item->originalTitle ?? '??';
        } else {
            $row['title'] = $item->grandparentTitle ?? '??';
            $row['season'] = $item->parentIndex ?? 0;
            $row['episode'] = $item->index ?? 0;
            $row['extra']['title'] = $item->title ?? $item->originalTitle ?? '??';

            $parentId = $item->grandparentRatingKey ?? $item->parentRatingKey ?? null;

            if (null !== $parentId) {
                $row['parent'] = $this->showInfo[$parentId] ?? [];
            }
        }

        $entity = Container::get(StateInterface::class)::fromArray($row);

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = $item->guid;
        }

        return $entity;
    }
}
