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
use DateTimeInterface;
use JsonException;
use JsonMachine\Items;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
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
        'media.scrobble',
    ];

    protected UriInterface|null $url = null;
    protected string|null $token = null;
    protected array $options = [];
    protected string $name = '';
    protected bool $loaded = false;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    public function setUp(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        array $options = []
    ): ServerInterface {
        return (new self($this->http, $this->logger))->setState($name, $url, $token, $options);
    }

    public function setLogger(LoggerInterface $logger): ServerInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public static function parseWebhook(ServerRequestInterface $request): StateInterface
    {
        $payload = ag($request->getParsedBody() ?? [], 'payload', null);

        if (null === $payload || null === ($json = json_decode((string)$payload, true))) {
            throw new HttpException('No payload.', 400);
        }

        $via = str_replace(' ', '_', ag($json, 'Server.title', 'Webhook'));
        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);

        if (true === Config::get('webhook.debug')) {
            saveWebhookPayload($request, "plex.{$via}.{$event}", $json);
        }

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('Not allowed Type [%s]', $type), 200);
        }

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('Not allowed Event [%s]', $event), 200);
        }

        $meta = match ($type) {
            StateInterface::TYPE_MOVIE => [
                'via' => $via,
                'title' => ag($json, 'Metadata.title', ag($json, 'Metadata.originalTitle', '??')),
                'year' => ag($json, 'Metadata.year', 0000),
                'date' => makeDate(ag($json, 'Metadata.originallyAvailableAt', 'now'))->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            StateInterface::TYPE_EPISODE => [
                'via' => $via,
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
            default => throw new HttpException('Invalid content type.', 400),
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

        $date = (int)ag(
            $json,
            'Metadata.lastViewedAt',
            ag($json, 'Metadata.updatedAt', ag($json, 'Metadata.addedAt', 0))
        );

        if (0 === $date) {
            throw new HttpException('Invalid Content date.', 400);
        }

        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => $isWatched,
            'meta' => $meta,
            ...self::getGuids($type, $json['Metadata']['Guid'] ?? [])
        ];

        return Container::get(StateInterface::class)::fromArray($row);
    }

    private function getHeaders(): array
    {
        $opts = [
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $this->token,
            ],
        ];

        if (null !== ($this->options['timeout'] ?? null)) {
            $opts['timeout'] = $this->options['timeout'];
        }

        if (null !== ($this->options['proxy'] ?? null)) {
            $opts['proxy'] = $this->options['proxy'];
        }

        if (null !== ($this->options['no_proxy'] ?? null)) {
            $opts['no_proxy'] = $this->options['no_proxy'];
        }

        if (null !== ($this->options['max_duration'] ?? null)) {
            $opts['max_duration'] = $this->options['max_duration'];
        }

        if (true === ($this->options['http2'] ?? false)) {
            $opts['http_version'] = '2.0';
        }

        return $opts;
    }

    protected function getLibraries(Closure $ok, Closure $error): array
    {
        if (null === $this->url) {
            throw new RuntimeException('No host was set.');
        }

        if (null === $this->token) {
            throw new RuntimeException('No token was set.');
        }

        try {
            $this->logger->debug(
                sprintf('Requesting libraries From %s.', $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath('/library/sections');

            $response = $this->http->request('GET', (string)$url, $this->getHeaders());

            $content = $response->getContent(false);

            $this->logger->debug(sprintf('===[ Sample from %s List library response ]===', $this->name));
            $this->logger->debug(!empty($content) ? mb_substr($content, 0, 200) : 'Empty response body');
            $this->logger->debug('===[ End ]===');

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

            $json = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            unset($content);

            $listDirs = ag($json, 'MediaContainer.Directory', []);

            if (empty($listDirs)) {
                $this->logger->notice(sprintf('No libraries found at %s.', $this->name));
                Data::add($this->name, 'no_import_update', true);
                return [];
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error(sprintf('Request to %s failed. Reason: \'%s\'.', $this->name, $e->getMessage()));
            Data::add($this->name, 'no_import_update', true);
            return [];
        } catch (JsonException $e) {
            $this->logger->error(
                sprintf('Unable to decode %s response. Reason: \'%s\'.', $this->name, $e->getMessage())
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
                    ['url' => $url]
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

                        $this->logger->notice(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));
                        foreach ($it as $entity) {
                            $this->processImport($mapper, $type, $cName, $entity, $after);
                        }
                        $this->logger->notice(
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
                            )
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    ['url' => $url]
                );
            }
        );
    }

    public function push(ExportInterface $mapper, DateTimeInterface|null $after = null): array
    {
        return $this->getLibraries(
            function (string $cName, string $type) use ($mapper, $after) {
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

                        $this->logger->notice(sprintf('Parsing Successful %s - %s response.', $this->name, $cName));
                        foreach ($it as $entity) {
                            $this->processExport($mapper, $type, $cName, $entity, $after);
                        }
                        $this->logger->notice(
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
                            )
                        );
                        return;
                    }
                };
            },
            function (string $cName, string $type, UriInterface|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    ['url' => $url]
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

            $isWatched = (int)(bool)($item->viewCount ?? false);

            $guids = self::getGuids($type, $item->Guid ?? []);

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

            $url = $this->url->withPath('/:' . (1 === $entity->watched ? '/scrobble' : '/unscrobble'))->withQuery(
                http_build_query(
                    [
                        'identifier' => 'com.plexapp.plugins.library',
                        'key' => $item->ratingKey,
                    ]
                )
            );

            $mapper->queue(
                $this->http->request(
                    'GET',
                    (string)$url,
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
            $this->logger->error($e->getMessage());
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

            $date = (int)($item->lastViewedAt ?? $item->updatedAt ?? $item->addedAt ?? 0);

            if (0 === $date) {
                $this->logger->error(sprintf('Ignoring %s. No date is set.', $iName));
                Data::increment($this->name, $type . '_ignored_no_date_is_set');
                return;
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
                ...self::getGuids($type, $item->Guid ?? [])
            ];

            $mapper->add($this->name, $iName, Container::get(StateInterface::class)::fromArray($row), [
                'after' => $after,
                self::OPT_IMPORT_UNWATCHED => (bool)($this->options[self::OPT_IMPORT_UNWATCHED] ?? false),
            ]);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    protected static function getGuids(string $type, array $guids): array
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
            if (empty($_id->id)) {
                continue;
            }

            [$key, $value] = explode('://', $_id->id);
            $key = strtolower($key);

            if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                return true;
            }
        }

        return false;
    }

    public function setState(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        array $opts = []
    ): ServerInterface {
        if (true === $this->loaded) {
            throw new RuntimeException('setState: already called once');
        }

        $this->name = $name;
        $this->url = $url;
        $this->token = $token;
        $this->options = $opts;
        $this->loaded = true;

        return $this;
    }
}
