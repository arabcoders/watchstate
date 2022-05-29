<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ImportInterface;
use App\Libs\Options;
use App\Libs\QueueRequests;
use Closure;
use DateInterval;
use DateTimeInterface;
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
    public const NAME = 'PlexBackend';

    protected const GUID_MAPPER = [
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
        'com.plexapp.agents.xbmcnfotv',
        'com.plexapp.agents.thetvdb',
        'com.plexapp.agents.hama',
    ];

    protected const GUID_AGENT_REPLACER = [
        'com.plexapp.agents.themoviedb://' => 'com.plexapp.agents.tmdb://',
        'com.plexapp.agents.xbmcnfo://' => 'com.plexapp.agents.imdb://',
        'com.plexapp.agents.thetvdb://' => 'com.plexapp.agents.tvdb://',
        'com.plexapp.agents.xbmcnfotv://' => 'com.plexapp.agents.tvdb://',
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

    protected const BACKEND_CAST_KEYS = [
        'lastViewedAt' => 'datetime',
        'updatedAt' => 'datetime',
        'addedAt' => 'datetime',
        'mediaTagVersion' => 'datetime',
        'duration' => 'duration_sec',
        'size' => 'size',
    ];

    /**
     * Parse hama agent guid.
     */
    private const HAMA_REGEX = '/(?P<source>(anidb|tvdb|tmdb|tsdb|imdb))\d?-(?P<id>[^\[\]]*)/';

    protected bool $initialized = false;
    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected array $options = [];
    protected string $name = '';
    protected array $persist = [];
    protected string $cacheKey = '';
    protected array $cache = [];

    protected string|int|null $uuid = null;
    protected string|int|null $user = null;

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
        protected CacheInterface $cacheIO
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

        $cloned->name = $name;
        $cloned->url = $url;
        $cloned->token = $token;
        $cloned->user = $userId;
        $cloned->uuid = $uuid;
        $cloned->options = $options;
        $cloned->persist = $persist;
        $cloned->initialized = true;

        $cloned->cache = [];
        $cloned->cacheKey = $cloned::NAME . '_' . $name;

        if ($cloned->cacheIO->has($cloned->cacheKey)) {
            $cloned->cache = $cloned->cacheIO->get($cloned->cacheKey);
        }

        return $cloned;
    }

    public function getServerUUID(bool $forceRefresh = false): int|string|null
    {
        if (false === $forceRefresh && null !== $this->uuid) {
            return $this->uuid;
        }

        $this->checkConfig();

        $url = $this->url->withPath('/');

        $this->logger->debug(sprintf('%s: Requesting server unique id.', $this->name), ['url' => $url]);

        $response = $this->http->request('GET', (string)$url, $this->getHeaders());

        if (200 !== $response->getStatusCode()) {
            $this->logger->error(
                sprintf(
                    '%s: Request to get server unique id responded with unexpected http status code \'%d\'.',
                    $this->name,
                    $response->getStatusCode()
                )
            );

            return null;
        }

        $json = json_decode(
            json:        $response->getContent(false),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        $this->uuid = ag($json, 'MediaContainer.machineIdentifier', null);

        return $this->uuid;
    }

    public function getUsersList(array $opts = []): array
    {
        $this->checkConfig(checkUrl: false);

        $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
            ->withPath('/api/v2/home/users/');

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
                    '%s: Request to get users list responded with unexpected code \'%d\'.',
                    $this->name,
                    $response->getStatusCode()
                )
            );
        }

        $json = json_decode(
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

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

    public function getName(): string
    {
        return $this->name ?? self::NAME;
    }

    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $logger = null;

        try {
            $logger = $opts[LoggerInterface::class] ?? Container::get(LoggerInterface::class);

            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'PlexMediaServer/')) {
                return $request;
            }

            $payload = ag($request->getParsedBody() ?? [], 'payload', null);

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'ITEM_ID' => ag($json, 'Metadata.ratingKey', ''),
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
            $logger?->error(sprintf('%s: %s', self::NAME, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        if (null === ($json = $request->getParsedBody())) {
            throw new HttpException(sprintf('%s: No payload.', self::NAME), 400);
        }

        $item = ag($json, 'Metadata', []);
        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', self::NAME, $type), 200);
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', self::NAME, $event), 200);
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        if (null !== $ignoreIds && in_array(ag($item, 'librarySectionID', '???'), $ignoreIds)) {
            throw new HttpException(
                sprintf(
                    '%s: Library id \'%s\' is ignored by user server config.',
                    self::NAME,
                    ag($item, 'librarySectionID', '???')
                ), 200
            );
        }

        $isTainted = in_array($event, self::WEBHOOK_TAINTED_EVENTS);

        if (null === ag($item, 'Guid', null)) {
            $item['Guid'] = [['id' => ag($item, 'guid')]];
        } else {
            $item['Guid'][] = ['id' => ag($item, 'guid')];
        }

        $guids = $this->getGuids(ag($item, 'Guid', []));
        $guids += Guid::makeVirtualGuid($this->name, (string)ag($item, 'ratingKey'));

        $row = [
            iFace::COLUMN_TYPE => $type,
            iFace::COLUMN_UPDATED => time(),
            iFace::COLUMN_WATCHED => (int)(bool)ag($item, 'viewCount', false),
            iFace::COLUMN_VIA => $this->name,
            iFace::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
            iFace::COLUMN_GUIDS => $guids,
            iFace::COLUMN_META_DATA => [
                $this->name => [
                    iFace::COLUMN_ID => (string)ag($item, 'ratingKey'),
                    iFace::COLUMN_TYPE => $type,
                    iFace::COLUMN_WATCHED => (string)(int)(bool)ag($item, 'viewCount', false),
                    iFace::COLUMN_VIA => $this->name,
                    iFace::COLUMN_TITLE => ag($item, ['title', 'originalTitle'], '??'),
                    iFace::COLUMN_GUIDS => $this->parseGuids(ag($item, 'Guid', [])),
                    iFace::COLUMN_META_DATA_ADDED_AT => (string)ag($item, 'addedAt'),
                ],
            ],
            iFace::COLUMN_EXTRA => [
                $this->name => [
                    iFace::COLUMN_EXTRA_EVENT => $event,
                    iFace::COLUMN_EXTRA_DATE => makeDate(time()),
                ],
            ],
        ];

        if (iFace::TYPE_EPISODE === $type) {
            $row[iFace::COLUMN_TITLE] = ag($item, 'grandparentTitle', '??');
            $row[iFace::COLUMN_SEASON] = ag($item, 'parentIndex', 0);
            $row[iFace::COLUMN_EPISODE] = ag($item, 'index', 0);
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_TITLE] = ag($item, 'grandparentTitle', '??');
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_SEASON] = (string)$row[iFace::COLUMN_SEASON];
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_EPISODE] = (string)$row[iFace::COLUMN_EPISODE];
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_TITLE] = ag(
                $item,
                ['title', 'originalTitle'],
                '??'
            );

            if (null !== ($parentId = ag($item, ['grandparentRatingKey', 'parentRatingKey'], null))) {
                $row[iFace::COLUMN_PARENT] = $this->getEpisodeParent($parentId);
                $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_PARENT] = $row[iFace::COLUMN_PARENT];
            }
        }

        if (null !== ($mediaYear = ag($item, ['grandParentYear', 'parentYear', 'year']))) {
            $row[iFace::COLUMN_YEAR] = (int)$mediaYear;
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_YEAR] = (string)$mediaYear;
        }

        if (null !== ($premiereDate = ag($item, 'originallyAvailableAt'))) {
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_DATE] = makeDate(
                $premiereDate
            )->format('Y-m-d');
        }

        if (null !== ($lastPlayedAt = ag($item, 'lastViewedAt')) & 1 === (int)(bool)ag($item, 'viewCount', false)) {
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_META_DATA_PLAYED_AT] = (string)$lastPlayedAt;
        }

        $entity = Container::get(iFace::class)::fromArray($row)->setIsTainted($isTainted);

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $message = sprintf('%s: No valid/supported external ids.', self::NAME);

            if (empty($item['Guid'])) {
                $message .= sprintf(' Most likely unmatched %s.', $entity->type);
            }

            $message .= sprintf(' [%s].', arrayToString(['guids' => ag($item, 'Guid', 'None')]));

            throw new HttpException($message, 400);
        }

        return $entity;
    }

    public function search(string $query, int $limit = 25, array $opts = []): array
    {
        $this->checkConfig();

        try {
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

            $this->logger->debug(sprintf('%s: Sending search request for \'%s\'.', $this->name, $query), [
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        '%s: Search request for \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $query,
                        $response->getStatusCode()
                    )
                );
            }

            $list = [];

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            foreach (ag($json, 'MediaContainer.Hub', []) as $item) {
                $type = ag($item, 'type');

                if ('show' !== $type && 'movie' !== $type) {
                    continue;
                }

                foreach (ag($item, 'Metadata', []) as $subItem) {
                    $list[] = $subItem;
                }
            }

            return true === ag($opts, Options::RAW_RESPONSE) ? $list : filterResponse($list, self::BACKEND_CAST_KEYS);
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(get_class($e) . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function searchId(string|int $id, array $opts = []): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/library/metadata/' . $id)->withQuery(
                http_build_query(['includeGuids' => 1])
            );

            $this->logger->debug(sprintf('%s: Sending get meta data for id \'%s\'.', $this->name, $id), [
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        '%s: Get metadata request for id \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $id,
                        $response->getStatusCode()
                    )
                );
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            return true === ag($opts, Options::RAW_RESPONSE) ? $json : filterResponse($json, self::BACKEND_CAST_KEYS);
        } catch (ExceptionInterface|JsonException $e) {
            throw new RuntimeException(get_class($e) . ': ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function searchMismatch(string|int $id, array $opts = []): array
    {
        $list = [];

        $this->checkConfig();

        try {
            // -- Get Content type.
            $url = $this->url->withPath('/library/sections/');

            $this->logger->debug(sprintf('%s: Sending get sections list.', $this->name), [
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        '%s: Get metadata request for id \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $id,
                        $response->getStatusCode()
                    )
                );
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $type = null;
            $found = false;

            foreach (ag($json, 'MediaContainer.Directory', []) as $section) {
                if ((int)ag($section, 'key') !== (int)$id) {
                    continue;
                }
                $found = true;
                $type = ag($section, 'type', 'unknown');
                break;
            }

            if (false === $found) {
                throw new RuntimeException(sprintf('%s: library id \'%s\' not found.', $this->name, $id));
            }

            if ('movie' !== $type && 'show' !== $type) {
                throw new RuntimeException(sprintf('%s: Library id \'%s\' is of unsupported type.', $this->name, $id));
            }

            $query = [
                'sort' => 'addedAt:asc',
                'includeGuids' => 1,
            ];

            if (iFace::TYPE_MOVIE === $type) {
                $query['type'] = 1;
            }

            $url = $this->url->withPath(sprintf('/library/sections/%d/all', $id))->withQuery(http_build_query($query));

            $this->logger->debug(sprintf('%s: Sending get library content for id \'%s\'.', $this->name, $id), [
                'url' => $url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                throw new RuntimeException(
                    sprintf(
                        '%s: Get metadata request for id \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $id,
                        $response->getStatusCode()
                    )
                );
            }

            $handler = function (string $type, array $item) use (&$list, $opts) {
                $url = $this->url->withPath(sprintf('/library/metadata/%d', ag($item, 'ratingKey')));

                if (iFace::TYPE_MOVIE !== $type) {
                    $response = $this->http->request('GET', (string)$url, $this->getHeaders());

                    $this->logger->debug(
                        sprintf('%s: get %s \'%s\' metadata.', $this->name, $type, ag($item, 'title')),
                        [
                            'url' => $url
                        ]
                    );

                    if (200 !== $response->getStatusCode()) {
                        $this->logger->warning(
                            sprintf(
                                '%s: Request to get \'%s\' metadata responded with unexpected http status code \'%d\'.',
                                $this->name,
                                ag($item, 'title'),
                                $response->getStatusCode()
                            )
                        );
                    }

                    $item = json_decode(
                        json:        $response->getContent(),
                        associative: true,
                        flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                    );

                    $item = ag($item, 'MediaContainer.Metadata.0', []);
                }

                $possibleTitles = $paths = $locations = $guids = $matches = [];
                $possibleTitlesList = ['title', 'originalTitle', 'titleSort'];
                foreach ($possibleTitlesList as $title) {
                    if (null === ($title = ag($item, $title))) {
                        continue;
                    }

                    $title = formatName($title);

                    if (true === in_array($title, $possibleTitles)) {
                        continue;
                    }

                    $possibleTitles[] = formatName($title);
                }

                foreach (ag($item, 'Location', []) as $path) {
                    $paths[] = formatName(basename(ag($path, 'path')));
                    $locations[] = ag($path, 'path');
                }

                foreach (ag($item, 'Media', []) as $leaf) {
                    foreach (ag($leaf, 'Part', []) as $path) {
                        $paths[] = formatName(basename(dirname(ag($path, 'file'))));
                        $locations[] = dirname(ag($path, 'file'));
                    }
                }

                foreach (ag($item, 'Guid', []) as $guid) {
                    $guids[] = ag($guid, 'id');
                }

                foreach ($paths ?? [] as $location) {
                    foreach ($possibleTitles as $title) {
                        if (true === str_contains($location, $title)) {
                            return;
                        }

                        $locationIsAscii = mb_detect_encoding($location, 'ASCII', true);
                        $titleIsAscii = mb_detect_encoding($location, 'ASCII', true);

                        if (true === $locationIsAscii && $titleIsAscii) {
                            similar_text($location, $title, $percent);
                        } else {
                            mb_similar_text($location, $title, $percent);
                        }

                        if ($percent >= $opts['coef']) {
                            return;
                        }

                        $matches[] = [
                            'path' => $location,
                            'title' => $title,
                            'similarity' => $percent,
                        ];
                    }
                }


                $metadata = [
                    'id' => (int)ag($item, 'ratingKey'),
                    'url' => (string)$url,
                    'type' => ucfirst($type),
                    'title' => ag($item, $possibleTitlesList, '??'),
                    'guids' => $guids,
                    'paths' => $locations,
                    'matching' => $matches,
                    'comments' => [],
                ];

                if (empty($paths)) {
                    $metadata['comments'][] = sprintf('No Path found for %s.', $type);
                } else {
                    $metadata['comments'][] = 'No title match path, Possible mismatch or outdated metadata.';
                }

                if (empty($guids)) {
                    $metadata['comment'] = 'No external ids were found. Indicate the possibility of unmatched item.';
                }

                $list[] = $metadata;
            };

            $it = Items::fromIterable(
                httpClientChunks($this->http->stream($response)),
                [
                    'pointer' => '/MediaContainer/Metadata',
                    'decoder' => new ErrorWrappingDecoder(
                        new ExtJsonDecoder(assoc: true, options: JSON_INVALID_UTF8_IGNORE)
                    )
                ]
            );

            foreach ($it as $entity) {
                if ($entity instanceof DecodingError) {
                    $this->logger->warning(
                        sprintf(
                            '%s: Failed to decode one of library id \'%s\' items. %s',
                            $this->name,
                            $id,
                            $entity->getErrorMessage()
                        ),
                        [
                            'payload' => $entity->getMalformedJson(),
                        ]
                    );
                    continue;
                }

                $handler($type, $entity);
            }
        } catch (ExceptionInterface|JsonException|\JsonMachine\Exception\InvalidArgumentException $e) {
            throw new RuntimeException(get_class($e) . ': ' . $e->getMessage(), $e->getCode(), $e);
        }

        return $list;
    }

    public function listLibraries(): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/library/sections');

            $this->logger->debug(sprintf('%s: Get list of server libraries.', $this->name), ['url' => $url]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        '%s: library list request responded with unexpected code \'%d\'.',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->notice(
                    sprintf(
                        '%s: Responded with empty list of libraries. Possibly the token has no access to the libraries?',
                        $this->name
                    )
                );
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(
                sprintf('%s: list of libraries request failed. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ],
            );
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Failed to decode library list JSON response. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            return [];
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $list = [];

        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');

            $list[] = [
                'ID' => $key,
                'Title' => ag($section, 'title', '???'),
                'Type' => $type,
                'Ignored' => null !== $ignoreIds && in_array($key, $ignoreIds) ? 'Yes' : 'No',
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
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request to \'%s\' responded with unexpected http status code \'%d\'.',
                                $this->name,
                                $cName,
                                $response->getStatusCode()
                            )
                        );
                        return;
                    }

                    try {
                        $this->logger->info(sprintf('%s: Parsing \'%s\' response.', $this->name, $cName));

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/MediaContainer/Metadata',
                                'decoder' => new ErrorWrappingDecoder(
                                    new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                )
                            ]
                        );

                        foreach ($it as $entity) {
                            if ($entity instanceof DecodingError) {
                                $this->logger->warning(
                                    sprintf(
                                        '%s: Failed to decode one of \'%s\' items. %s',
                                        $this->name,
                                        $cName,
                                        $entity->getErrorMessage()
                                    ),
                                    [
                                        'payload' => $entity->getMalformedJson(),
                                    ]
                                );
                                continue;
                            }
                            $this->processImport($mapper, $type, $cName, $entity, $after);
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to find items in \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    } catch (Throwable $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to handle \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage(),
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    }

                    $this->logger->info(sprintf('%s: Parsing \'%s\' response is complete.', $this->name, $cName));
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('%s: Error encountered in \'%s\' request. %s', $this->name, $cName, $e->getMessage()),
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

    public function push(array $entities, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        $this->checkConfig();

        $requests = [];

        $count = count($entities);

        foreach ($entities as $key => $entity) {
            if (true !== ($entity instanceof StateEntity)) {
                continue;
            }

            if (false === (bool)ag($this->options, Options::IGNORE_DATE, false)) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    continue;
                }
            }

            $iName = $entity->getName();
            $metadata = ag($entity->metadata, $this->name, []);

            if (null === ag($metadata, iFace::COLUMN_ID, null)) {
                $this->logger->warning(sprintf('%s: Ignoring \'%s\'. No metadata relation map.', $this->name, $iName), [
                    'id' => $entity->id,
                ]);
                continue;
            }

            try {
                $url = $this->url->withPath('/library/metadata/' . ag($metadata, iFace::COLUMN_ID))
                    ->withQuery(
                        http_build_query(
                            [
                                'includeGuids' => 1
                            ]
                        )
                    );

                if ($count < 20) {
                    $this->logger->debug(sprintf('%s: Requesting \'%s\' state.', $this->name, $iName), [
                        'url' => $url
                    ]);
                }

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($this->getHeaders(), [
                        'user_data' => [
                            'id' => $key,
                            'suid' => ag($metadata, iFace::COLUMN_ID),
                        ]
                    ])
                );
            } catch (Throwable $e) {
                $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]);
            }
        }

        foreach ($requests as $response) {
            try {
                if (null === ($id = ag($response->getInfo('user_data'), 'id'))) {
                    $this->logger->error(sprintf('%s: Unable to get item entity state.', $this->name));
                    continue;
                }

                $state = $entities[$id];

                assert($state instanceof iFace);

                switch ($response->getStatusCode()) {
                    case 200:
                        break;
                    case 404:
                        $this->logger->error(
                            sprintf(
                                '%s: Request to get \'%s\' metadata returned \'404 Not Found\' error.',
                                $this->name,
                                $state->getName(),

                            ), [
                                'id' => ag($response->getInfo('user_data'), 'suid', '??'),
                            ]
                        );
                        continue 2;
                    default:
                        $this->logger->error(
                            sprintf(
                                '%s: Request to get \'%s\' metadata responded with unexpected http status code \'%d\'.',
                                $this->name,
                                $state->getName(),
                                $response->getStatusCode()
                            )
                        );
                        continue 2;
                }

                $json = json_decode(
                    json:        $response->getContent(false),
                    associative: true,
                    flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                $json = ag($json, 'MediaContainer.Metadata', [])[0] ?? [];

                if (empty($json)) {
                    $this->logger->error(
                        sprintf('%s: Ignoring \'%s\'. Backend returned empty result.', $this->name, $state->getName())
                    );
                    continue;
                }

                $isWatched = (int)(bool)ag($json, 'viewCount', 0);

                if (false === (bool)ag($this->options, Options::IGNORE_DATE, false)) {
                    $date = max((int)ag($json, 'lastViewedAt', 0), (int)ag($json, 'addedAt', 0));

                    if (0 === $date) {
                        $this->logger->error(
                            sprintf(
                                '%s: Ignoring \'%s\'. No date is set on backend object.',
                                $this->name,
                                $state->getName()
                            ),
                            $json
                        );
                        continue;
                    }

                    $date = makeDate($date);

                    $timeExtra = (int)(ag($this->options, Options::EXPORT_ALLOWED_TIME_DIFF, 10));

                    if ($date->getTimestamp() >= ($timeExtra + $state->updated)) {
                        $this->logger->notice(
                            sprintf(
                                '%s: Ignoring \'%s\'. Record time is older than backend time.',
                                $this->name,
                                $state->getName()
                            ),
                            [
                                'record' => makeDate($state->updated),
                                'backend' => $date,
                                'time_difference' => $date->getTimestamp() - $state->updated,
                                'extra_margin' => [
                                    Options::EXPORT_ALLOWED_TIME_DIFF => $timeExtra,
                                ],
                            ]
                        );
                        continue;
                    }
                }

                if ($state->watched === $isWatched) {
                    $this->logger->info(
                        sprintf('%s: Ignoring \'%s\'. Play state is identical.', $this->name, $state->getName())
                    );
                    continue;
                }

                $url = $this->url->withPath($state->isWatched() ? '/:/scrobble' : '/:/unscrobble')->withQuery(
                    http_build_query(
                        [
                            'identifier' => 'com.plexapp.plugins.library',
                            'key' => ag($json, 'ratingKey'),
                        ]
                    )
                );

                $this->logger->debug(
                    sprintf(
                        '%s: Changing \'%s\' play state to \'%s\'.',
                        $this->name,
                        $state->getName(),
                        $state->isWatched() ? 'Played' : 'Unplayed',
                    ),
                    [
                        'backend' => $isWatched ? 'Played' : 'Unplayed',
                        'url' => $url,
                    ]
                );

                if (false === (bool)ag($this->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            'GET',
                            (string)$url,
                            array_replace_recursive($this->getHeaders(), [
                                'user_data' => [
                                    'itemName' => $state->getName(),
                                    'server' => $this->name,
                                    'state' => $state->isWatched() ? 'Played' : 'Unplayed',
                                ]
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ]);
            }
        }

        unset($requests);

        return [];
    }

    public function export(ImportInterface $mapper, QueueRequests $queue, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            ok: function (string $cName, string $type) use ($mapper, $queue, $after) {
                return function (ResponseInterface $response) use ($mapper, $queue, $cName, $type, $after) {
                    if (200 !== $response->getStatusCode()) {
                        $this->logger->error(
                            sprintf(
                                '%s: Request for \'%s\' responded with unexpected http status code (%d).',
                                $this->name,
                                $cName,
                                $response->getStatusCode()
                            )
                        );
                        return;
                    }

                    try {
                        $this->logger->info(sprintf('%s: Parsing \'%s\' response.', $this->name, $cName));

                        $it = Items::fromIterable(
                            httpClientChunks($this->http->stream($response)),
                            [
                                'pointer' => '/MediaContainer/Metadata',
                                'decoder' => new ErrorWrappingDecoder(
                                    new ExtJsonDecoder(options: JSON_INVALID_UTF8_IGNORE)
                                )
                            ]
                        );

                        foreach ($it as $entity) {
                            if ($entity instanceof DecodingError) {
                                $this->logger->notice(
                                    sprintf(
                                        '%s: Failed to decode one of \'%s\' items. %s',
                                        $this->name,
                                        $cName,
                                        $entity->getErrorMessage()
                                    ),
                                    [
                                        'payload' => $entity->getMalformedJson(),
                                    ]
                                );
                                continue;
                            }
                            $this->processExport($mapper, $queue, $type, $cName, $entity, $after);
                        }
                    } catch (PathNotFoundException $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to find items in \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage()
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    } catch (Throwable $e) {
                        $this->logger->error(
                            sprintf(
                                '%s: Failed to handle \'%s\' response. %s',
                                $this->name,
                                $cName,
                                $e->getMessage(),
                            ),
                            [
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'kind' => get_class($e),
                            ],
                        );
                    }

                    $this->logger->info(sprintf('%s: Parsing \'%s\' response is complete.', $this->name, $cName));
                };
            },
            error: function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('%s: Error encountered in \'%s\' request. %s', $this->name, $cName, $e->getMessage()),
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

    /**
     * @throws InvalidArgumentException
     */
    public function __destruct()
    {
        if (true === (bool)ag($this->options, Options::DRY_RUN)) {
            return;
        }

        if (!empty($this->cacheKey) && !empty($this->cache) && true === $this->initialized) {
            $this->cacheIO->set($this->cacheKey, $this->cache, new DateInterval('P3D'));
        }
    }

    protected function getHeaders(): array
    {
        $opts = [
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $this->token,
            ],
        ];

        return array_replace_recursive($this->options['client'] ?? [], $opts);
    }

    protected function getLibraries(Closure $ok, Closure $error, bool $includeParent = false): array
    {
        $this->checkConfig();

        try {
            $url = $this->url->withPath('/library/sections');

            $this->logger->debug(sprintf('%s: Requesting list of server libraries.', $this->name), [
                'url' => (string)$url
            ]);

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                $this->logger->error(
                    sprintf(
                        '%s: Request to get list of server libraries responded with unexpected code \'%d\'.',
                        $this->name,
                        $response->getStatusCode()
                    )
                );
                Data::add($this->name, 'no_import_update', true);
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->warning(
                    sprintf('%s: Request to get list of server libraries responded with empty list.', $this->name)
                );
                Data::add($this->name, 'no_import_update', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(
                sprintf('%s: Request to get server libraries failed. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                ],
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Unable to decode get server libraries JSON response. %s', $this->name, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        if (null !== ($ignoreIds = ag($this->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => (int)trim($v), explode(',', (string)$ignoreIds));
        }

        $promises = [];
        $ignored = $unsupported = 0;

        if (true === $includeParent) {
            foreach ($listDirs as $section) {
                $key = (int)ag($section, 'key');
                $title = trim((string)ag($section, 'title', '???'));

                if ('show' !== ag($section, 'type', 'unknown')) {
                    continue;
                }

                $cName = sprintf('(%s) - (%s:%s)', trim($title), 'show', $key);

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

                $this->logger->debug(sprintf('%s: Requesting \'%s\' tv shows external ids.', $this->name, $cName), [
                    'url' => $url
                ]);

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
                            '%s: Request for \'%s\' tv shows external ids has failed. %s',
                            $this->name,
                            $cName,
                            $e->getMessage()
                        ),
                        [
                            'url' => $url,
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                        ]
                    );
                    continue;
                }
            }
        }

        foreach ($listDirs as $section) {
            $key = (int)ag($section, 'key');
            $type = ag($section, 'type', 'unknown');
            $title = trim((string)ag($section, 'title', '???'));

            if ('movie' !== $type && 'show' !== $type) {
                $unsupported++;
                $this->logger->info(sprintf('%s: Skipping \'%s\'. Unsupported type.', $this->name, $title), [
                    'id' => $key,
                    'type' => $type,
                ]);
                continue;
            }

            $type = $type === 'movie' ? iFace::TYPE_MOVIE : iFace::TYPE_EPISODE;

            if (null !== $ignoreIds && true === in_array($key, $ignoreIds)) {
                $ignored++;
                $this->logger->info(sprintf('%s: Skipping \'%s\'. Ignored by user.', $this->name, $title), [
                    'id' => $key,
                    'type' => $type,
                ]);
                continue;
            }

            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            $url = $this->url->withPath(sprintf('/library/sections/%d/all', $key))->withQuery(
                http_build_query(
                    [
                        'type' => 'movie' === $type ? 1 : 4,
                        'sort' => 'addedAt:asc',
                        'includeGuids' => 1,
                    ]
                )
            );

            $this->logger->debug(sprintf('%s: Requesting \'%s\' media items.', $this->name, $cName), [
                'url' => $url
            ]);

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
                    sprintf('%s: Request for \'%s\' media items has failed. %s', $this->name, $cName, $e->getMessage()),
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
            $this->logger->warning(sprintf('%s: No library requests were made.', $this->name), [
                'total' => count($listDirs),
                'ignored' => $ignored,
                'unsupported' => $unsupported,
            ]);
            Data::add($this->name, 'no_import_update', true);
            return [];
        }

        return $promises;
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

            if (iFace::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $library,
                    $item->title ?? $item->originalTitle ?? '??',
                    $item->year ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%sx%s)]',
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        str_pad((string)($item->parentIndex ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($item->index ?? 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                $this->logger->debug(sprintf('%s: Processing \'%s\' Payload.', $this->name, $iName), [
                    'payload' => (array)$item,
                ]);
            }

            $date = max((int)($item->lastViewedAt ?? 0), (int)($item->addedAt ?? 0));

            if (0 === $date) {
                $this->logger->debug(
                    sprintf('%s: Ignoring \'%s\'. Date is not set on backend object.', $this->name, $iName),
                    [
                        'payload' => $item,
                    ]
                );
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $entity = $this->createEntity($item, $type);

            if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
                if (true === Config::get('debug.import')) {
                    $name = Config::get('tmpDir') . '/debug/' . $this->name . '.' . $item->ratingKey . '.json';

                    if (!file_exists($name)) {
                        file_put_contents(
                            $name,
                            json_encode(
                                $item,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE
                            )
                        );
                    }
                }

                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($item->Guid)) {
                    $message .= sprintf(' Most likely unmatched %s.', $entity->type);
                }

                if (null === ($item->Guid ?? null)) {
                    $item->Guid = [['id' => $item->guid]];
                } else {
                    $item->Guid[] = ['id' => $item->guid];
                }

                $this->logger->info($message, ['guids' => !empty($item->Guid) ? $item->Guid : 'None']);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            $mapper->add($this->name, $this->name . ' - ' . $iName, $entity, ['after' => $after]);
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }
    }

    protected function processExport(
        ImportInterface $mapper,
        QueueRequests $queue,
        string $type,
        string $library,
        StdClass $item,
        DateTimeInterface|null $after = null
    ): void {
        try {
            Data::increment($this->name, $type . '_total');

            if (iFace::TYPE_MOVIE === $type) {
                $iName = sprintf(
                    '%s - [%s (%d)]',
                    $library,
                    $item->title ?? $item->originalTitle ?? '??',
                    $item->year ?? 0000
                );
            } else {
                $iName = trim(
                    sprintf(
                        '%s - [%s - (%sx%s)]',
                        $library,
                        $item->grandparentTitle ?? $item->originalTitle ?? '??',
                        str_pad((string)($item->parentIndex ?? 0), 2, '0', STR_PAD_LEFT),
                        str_pad((string)($item->index ?? 0), 3, '0', STR_PAD_LEFT),
                    )
                );
            }

            $date = max((int)($item->lastViewedAt ?? 0), (int)($item->addedAt ?? 0));

            if (0 === $date) {
                $this->logger->notice(
                    sprintf('%s: Ignoring \'%s\'. Date is not set on backend object.', $this->name, $iName),
                    [
                        'payload' => get_object_vars($item),
                    ]
                );
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
            }

            $rItem = $this->createEntity($item, $type);

            if (!$rItem->hasGuids() && !$rItem->hasRelativeGuid()) {
                $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

                if (empty($item->Guid)) {
                    $message .= sprintf(' Most likely unmatched %s.', $rItem->type);
                }

                if (null === ($item->Guid ?? null)) {
                    $item->Guid = [['id' => $item->guid]];
                } else {
                    $item->Guid[] = ['id' => $item->guid];
                }

                $this->logger->debug($message, ['guids' => !empty($item->Guid) ? $item->Guid : 'None']);

                Data::increment($this->name, $type . '_ignored_no_supported_guid');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
                if (null !== $after && $rItem->updated >= $after->getTimestamp()) {
                    $this->logger->debug(
                        sprintf(
                            '%s: Ignoring \'%s\'. Backend reported date is equal or newer than last sync date.',
                            $this->name,
                            $iName
                        )
                    );
                    Data::increment($this->name, $type . '_ignored_date_is_equal_or_higher');
                    return;
                }
            }

            if (null === ($entity = $mapper->get($rItem))) {
                $this->logger->debug(
                    sprintf(
                        '%s: Ignoring \'%s\'. Media item is not imported.',
                        $this->name,
                        $iName,
                    ),
                    [
                        'played' => $rItem->isWatched() ? 'Yes' : 'No',
                        'guids' => $rItem->hasGuids() ? $rItem->getGuids() : 'None',
                        'rGuids' => $rItem->hasRelativeGuid() ? $rItem->getRelativeGuids() : 'None',
                    ]
                );
                Data::increment($this->name, $type . '_ignored_not_found_in_db');
                return;
            }

            if ($rItem->watched === $entity->watched) {
                $this->logger->debug(sprintf('%s: Ignoring \'%s\'. Played state is identical.', $this->name, $iName), [
                    'backend' => $entity->isWatched() ? 'Played' : 'Unplayed',
                    'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                ]);
                Data::increment($this->name, $type . '_ignored_state_unchanged');
                return;
            }

            if (false === ag($this->options, Options::IGNORE_DATE, false)) {
                if ($rItem->updated >= $entity->updated) {
                    $this->logger->debug(
                        sprintf(
                            '%s: Ignoring \'%s\'. Record date is newer or equal to backend item.',
                            $this->name,
                            $iName
                        ),
                        [
                            'backend' => makeDate($entity->updated),
                            'remote' => makeDate($rItem->updated),
                        ]
                    );
                    Data::increment($this->name, $type . '_ignored_date_is_newer');
                    return;
                }
            }

            $url = $this->url->withPath('/:' . ($entity->isWatched() ? '/scrobble' : '/unscrobble'))->withQuery(
                http_build_query(
                    [
                        'identifier' => 'com.plexapp.plugins.library',
                        'key' => $item->ratingKey,
                    ]
                )
            );

            $this->logger->debug(
                sprintf(
                    '%s: Changing \'%s\' play state to \'%s\'.',
                    $this->name,
                    $iName,
                    $entity->isWatched() ? 'Played' : 'Unplayed',
                ),
                [
                    'remote' => $rItem->isWatched() ? 'Played' : 'Unplayed',
                    'url' => $url,
                ]
            );

            if (false === (bool)ag($this->options, Options::DRY_RUN, false)) {
                $queue->add(
                    $this->http->request(
                        'GET',
                        (string)$url,
                        array_replace_recursive($this->getHeaders(), [
                            'user_data' => [
                                'itemName' => $iName,
                                'server' => $this->name,
                                'state' => $entity->isWatched() ? 'Played' : 'Unplayed',
                            ]
                        ])
                    )
                );
            }
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
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

        $iName = sprintf(
            '%s - [%s (%d)]',
            $library,
            ag($item, ['title', 'originalTitle'], '??'),
            ag($item, 'year', '0000')
        );

        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
            $this->logger->debug(sprintf('%s: Processing \'%s\' Payload.', $this->name, $iName), [
                'payload' => (array)$item,
            ]);
        }

        if (!$this->hasSupportedGuids(guids: $item->Guid)) {
            if (null === ($item->Guid ?? null)) {
                $item->Guid = [['id' => $item->guid]];
            } else {
                $item->Guid[] = ['id' => $item->guid];
            }

            $message = sprintf('%s: Ignoring \'%s\'. No valid/supported external ids.', $this->name, $iName);

            if (empty($item->Guid)) {
                $message .= ' Most likely unmatched TV show.';
            }

            $this->logger->info($message, ['guids' => !empty($item->Guid) ? $item->Guid : 'None']);

            return;
        }

        $this->cache['shows'][$item->ratingKey] = Guid::fromArray($this->getGuids($item->Guid))->getAll();
    }

    protected function parseGuids(array $guids): array
    {
        $guid = [];

        foreach ($guids as $_id) {
            try {
                $val = is_object($_id) ? $_id->id : $_id['id'];

                if (empty($val)) {
                    continue;
                }

                if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                    // -- DO NOT accept plex relative unique ids, we generate our own.
                    if (substr_count($val, '/') >= 3) {
                        continue;
                    }
                    $val = $this->parseLegacyAgent($val);
                }

                if (false === str_contains($val, '://')) {
                    continue;
                }

                [$key, $value] = explode('://', $val);
                $key = strtolower($key);

                $guid[$key] = $value;
            } catch (Throwable) {
                continue;
            }
        }

        ksort($guid);

        return $guid;
    }

    protected function getGuids(array $guids): array
    {
        $guid = [];

        foreach ($guids as $_id) {
            try {
                $val = is_object($_id) ? $_id->id : $_id['id'];

                if (empty($val)) {
                    continue;
                }

                if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                    // -- DO NOT accept plex relative unique ids, we generate our own.
                    if (substr_count($val, '/') >= 3) {
                        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                            $this->logger->warning(
                                sprintf(
                                    '%s: Parsing \'%s\' custom agent identifier is not supported.',
                                    $this->name,
                                    $val
                                )
                            );
                        }
                        continue;
                    }
                    $val = $this->parseLegacyAgent($val);
                }

                if (false === str_contains($val, '://')) {
                    $this->logger->info(
                        sprintf(
                            '%s: Encountered unsupported external id format. Possibly duplicate movie?',
                            $this->name
                        ),
                        [
                            'guid' => $val
                        ]
                    );
                    continue;
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
            } catch (Throwable $e) {
                $this->logger->error(
                    sprintf(
                        '%s: Error occurred while parsing external id. %s',
                        $this->name,
                        $e->getMessage()
                    ),
                    [
                        'guid' => $val ?? null,
                    ]
                );
                continue;
            }
        }

        ksort($guid);

        return $guid;
    }

    protected function hasSupportedGuids(array $guids): bool
    {
        foreach ($guids as $_id) {
            try {
                $val = is_object($_id) ? $_id->id : $_id['id'];

                if (empty($val)) {
                    continue;
                }

                if (true === str_starts_with($val, 'com.plexapp.agents.')) {
                    // -- DO NOT accept plex relative unique ids, we generate our own.
                    if (substr_count($val, '/') >= 3) {
                        if (true === (bool)ag($this->options, Options::DEBUG_TRACE)) {
                            $this->logger->warning(
                                sprintf(
                                    '%s: Parsing this \'%s\' custom agent identifier is not supported.',
                                    $this->name,
                                    $val
                                )
                            );
                        }
                        continue;
                    }
                    $val = $this->parseLegacyAgent($val);
                }

                if (false === str_contains($val, '://')) {
                    continue;
                }

                [$key, $value] = explode('://', $val);
                $key = strtolower($key);

                if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                    return true;
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    sprintf(
                        '%s: An error occurred while parsing external id. %s',
                        $this->name,
                        $e->getMessage()
                    ),
                    [
                        'id' => $val ?? null,
                        'list' => $guids,
                    ]
                );
                continue;
            }
        }

        return false;
    }

    protected function checkConfig(bool $checkUrl = true, bool $checkToken = true): void
    {
        if (true === $checkUrl && !($this->url instanceof UriInterface)) {
            throw new RuntimeException(self::NAME . ': No host was set.');
        }

        if (true === $checkToken && null === $this->token) {
            throw new RuntimeException(self::NAME . ': No token was set.');
        }
    }

    protected function createEntity(StdClass $item, string $type): StateEntity
    {
        if (null === ($item->Guid ?? null)) {
            $item->Guid = [['id' => $item->guid]];
        } else {
            $item->Guid[] = ['id' => $item->guid];
        }

        $date = max((int)($item->lastViewedAt ?? 0), (int)($item->addedAt ?? 0));

        $guids = $this->getGuids($item->Guid ?? []);
        $guids += Guid::makeVirtualGuid($this->name, (string)$item->ratingKey);

        $row = [
            iFace::COLUMN_TYPE => $type,
            iFace::COLUMN_UPDATED => $date,
            iFace::COLUMN_WATCHED => (int)(bool)($item->viewCount ?? false),
            iFace::COLUMN_VIA => $this->name,
            iFace::COLUMN_TITLE => $item->title ?? $item->originalTitle ?? '??',
            iFace::COLUMN_GUIDS => $guids,
            iFace::COLUMN_META_DATA => [
                $this->name => [
                    iFace::COLUMN_ID => (string)$item->ratingKey,
                    iFace::COLUMN_TYPE => $type,
                    iFace::COLUMN_WATCHED => (string)(int)(bool)($item->viewCount ?? false),
                    iFace::COLUMN_VIA => $this->name,
                    iFace::COLUMN_TITLE => $item->title ?? $item->originalTitle ?? '??',
                    iFace::COLUMN_GUIDS => $this->parseGuids($item->Guid ?? []),
                    iFace::COLUMN_META_DATA_ADDED_AT => (string)$item->addedAt,
                ],
            ],
            iFace::COLUMN_EXTRA => [],
        ];

        if (iFace::TYPE_EPISODE === $type) {
            $row[iFace::COLUMN_TITLE] = $item->grandparentTitle ?? '??';
            $row[iFace::COLUMN_SEASON] = $item->parentIndex ?? 0;
            $row[iFace::COLUMN_EPISODE] = $item->index ?? 0;
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_TITLE] = $item->grandparentTitle ?? '??';
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_SEASON] = (string)$row[iFace::COLUMN_SEASON];
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_EPISODE] = (string)$row[iFace::COLUMN_EPISODE];
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_TITLE] = $item->title ?? $item->originalTitle ?? '??';

            $parentId = $item->grandparentRatingKey ?? $item->parentRatingKey ?? null;

            if (null !== $parentId) {
                $row[iFace::COLUMN_PARENT] = $this->getEpisodeParent($parentId);
                $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_PARENT] = $row[iFace::COLUMN_PARENT];
            }
        }

        if (null !== ($mediaYear = $item->grandParentYear ?? $item->parentYear ?? $item->year ?? null)) {
            $row[iFace::COLUMN_YEAR] = (int)$mediaYear;
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_YEAR] = (string)$mediaYear;
        }

        if (null !== ($item->originallyAvailableAt ?? null)) {
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_META_DATA_EXTRA][iFace::COLUMN_META_DATA_EXTRA_DATE] = makeDate(
                $item->originallyAvailableAt
            )->format('Y-m-d');
        }

        if (null !== ($item->lastViewedAt ?? null)) {
            $row[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_META_DATA_PLAYED_AT] = (string)$item->lastViewedAt;
        }

        return Container::get(iFace::class)::fromArray($row);
    }

    protected function getEpisodeParent(int|string $id): array
    {
        if (array_key_exists($id, $this->cache['shows'] ?? [])) {
            return $this->cache['shows'][$id];
        }

        $url = (string)$this->url->withPath('/library/metadata/' . $id);

        try {
            $response = $this->http->request('GET', $url, $this->getHeaders());

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $json = ag($json, 'MediaContainer.Metadata')[0] ?? [];

            if (null === ($type = ag($json, 'type')) || 'show' !== $type) {
                return [];
            }

            if (null === ($json['Guid'] ?? null)) {
                $json['Guid'] = [['id' => $json['guid']]];
            } else {
                $json['Guid'][] = ['id' => $json['guid']];
            }

            if (!$this->hasSupportedGuids(guids: $json['Guid'])) {
                $this->cache['shows'][$id] = [];
                return [];
            }

            $this->cache['shows'][$id] = Guid::fromArray($this->getGuids($json['Guid']))->getAll();

            return $this->cache['shows'][$id];
        } catch (ExceptionInterface $e) {
            $this->logger->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
                'url' => $url,
            ]);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('%s: Unable to decode show id \'%s\' JSON response. %s', $this->name, $id, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'url' => $url,
                ]
            );
            return [];
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s: Failed to handle show id \'%s\' response. %s', $this->name, $id, $e->getMessage()),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'url' => $url,
                ]
            );
            return [];
        }
    }

    /**
     * Parse legacy plex agents.
     *
     * @param string $agent
     *
     * @return string
     * @see https://github.com/ZeroQI/Hama.bundle/issues/510
     */
    protected function parseLegacyAgent(string $agent): string
    {
        try {
            if (false === in_array(before($agent, '://'), self::SUPPORTED_LEGACY_AGENTS)) {
                return $agent;
            }

            // -- Handle hama plex agent. This is multi source agent.
            if (true === str_starts_with($agent, 'com.plexapp.agents.hama')) {
                $guid = after($agent, '://');

                if (1 !== preg_match(self::HAMA_REGEX, $guid, $matches)) {
                    return $agent;
                }

                if (null === ($source = ag($matches, 'source')) || null === ($sourceId = ag($matches, 'id'))) {
                    return $agent;
                }

                return str_replace('tsdb', 'tmdb', $source) . '://' . before($sourceId, '?');
            }

            $agent = str_replace(
                array_keys(self::GUID_AGENT_REPLACER),
                array_values(self::GUID_AGENT_REPLACER),
                $agent
            );

            $agentGuid = explode('://', after($agent, 'agents.'));

            return $agentGuid[0] . '://' . before($agentGuid[1], '?');
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('%s: Error parsing plex legacy agent identifier. %s', $this->name, $e->getMessage()),
                [
                    'guid' => $agent,
                ]
            );
            return $agent;
        }
    }

    protected function getUserToken(int|string $userId): int|string|null
    {
        try {
            $uuid = $this->getServerUUID();

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost(
                'plex.tv'
            )->withPath(sprintf('/api/v2/home/users/%s/switch', $userId));

            $this->logger->debug(sprintf('%s: Requesting temp token for user id \'%s\'.', $this->name, $userId), [
                'url' => (string)$url
            ]);

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
                        '%s: Request to get temp token for user id \'%s\' responded with unexpected http status code \'%d\'.',
                        $this->name,
                        $userId,
                        $response->getStatusCode()
                    )
                );

                return null;
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            $tempToken = ag($json, 'authToken', null);

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
                ->withPath('/api/v2/resources')->withQuery(
                    http_build_query(
                        [
                            'includeIPv6' => 1,
                            'includeHttps' => 1,
                            'includeRelay' => 1
                        ]
                    )
                );

            $this->logger->debug(
                sprintf('%s: Requesting real server token for user id \'%s\'.', $this->name, $userId),
                [
                    'url' => (string)$url
                ]
            );

            $response = $this->http->request('GET', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $tempToken,
                    'X-Plex-Client-Identifier' => $uuid,
                ],
            ]);

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            foreach ($json ?? [] as $server) {
                if (ag($server, 'clientIdentifier') !== $uuid) {
                    continue;
                }
                return ag($server, 'accessToken');
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s: %s', $this->name, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
            return null;
        }
    }
}
