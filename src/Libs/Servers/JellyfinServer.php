<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Data;
use App\Libs\Entity\StateEntity;
use App\Libs\Extends\Request;
use App\Libs\Guid;
use App\Libs\HttpException;
use App\Libs\Mappers\ExportInterface;
use App\Libs\Mappers\ImportInterface;
use Closure;
use DateTimeInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class JellyfinServer implements ServerInterface
{
    private const GUID_MAPPER = [
        'plex' => Guid::GUID_PLEX,
        'imdb' => Guid::GUID_IMDB,
        'tmdb' => Guid::GUID_TMDB,
        'tvdb' => Guid::GUID_TVDB,
        'tvmaze' => Guid::GUID_TVMAZE,
        'tvrage' => Guid::GUID_TVRAGE,
        'anidb' => Guid::GUID_ANIDB,
    ];

    protected const WEBHOOK_ALLOWED_TYPES = [
        'Movie',
        'Episode',
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'ItemAdded',
        'UserDataSaved',
    ];

    protected Uri|null $url = null;
    protected string|null $token = null;
    protected string|null $user = null;
    protected array $options = [];
    protected string $name = '';
    protected bool $loaded = false;
    protected bool $isEmby = false;

    public function __construct(protected Request $http, protected LoggerInterface $logger)
    {
    }

    public function setUp(string $name, Uri $url, string|int|null $token = null, array $options = []): ServerInterface
    {
        return (new self($this->http, $this->logger))->setState($name, $url, $token, $options);
    }

    public function setLogger(LoggerInterface $logger): ServerInterface
    {
        $this->logger = $logger;

        return $this;
    }

    public static function parseWebhook(ServerRequestInterface $request): StateEntity
    {
        if (null === ($json = json_decode($request->getBody()->getContents(), true))) {
            throw new HttpException('No payload.', 400);
        }

        $via = str_replace(' ', '_', ag($json, 'ServerName', 'Webhook'));
        $event = ag($json, 'NotificationType', 'unknown');
        $type = ag($json, 'ItemType', 'not_found');

        if (true === Config::get('webhook.debug')) {
            saveWebhookPayload($request, "jellyfin.{$via}.{$event}", $json);
        }

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('Not allowed Type [%s]', $type), 200);
        }

        $type = strtolower($type);

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('Not allowed Event [%s]', $event), 200);
        }

        $date = $json['LastPlayedDate'] ?? $json['DateCreated'] ?? $json['PremiereDate'] ?? $json['Timestamp'] ?? null;

        if (StateEntity::TYPE_MOVIE === $type) {
            $meta = [
                'via' => $via,
                'title' => ag($json, 'Name', '??'),
                'year' => ag($json, 'Year', 0000),
                'webhook' => [
                    'event' => $event,
                ],
            ];
        } else {
            $meta = [
                'via' => $via,
                'series' => ag($json, 'SeriesName', '??'),
                'year' => ag($json, 'Year', 0000),
                'season' => ag($json, 'SeasonNumber', 0),
                'episode' => ag($json, 'EpisodeNumber', 0),
                'title' => ag($json, 'Name', '??'),
                'webhook' => [
                    'event' => $event,
                ],
            ];
        }

        $guids = [];

        foreach ($json as $key => $val) {
            if (str_starts_with($key, 'Provider_')) {
                $guids[self::afterString($key, 'Provider_')] = $val;
            }
        }

        $isWatched = (int)(bool)ag($json, 'Played', ag($json, 'PlayedToCompletion', 0));

        $row = [
            'type' => $type,
            'updated' => makeDate($date)->getTimestamp(),
            'watched' => $isWatched,
            'meta' => $meta,
            ...self::getGuids($type, $guids)
        ];

        return new StateEntity($row);
    }

    private function getHeaders(): array
    {
        $opts = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => $this->options['timeout'] ?? 0,
            RequestOptions::CONNECT_TIMEOUT => $this->options['connect_timeout'] ?? 0,
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
            ],
        ];

        if (true === $this->isEmby) {
            $opts[RequestOptions::HEADERS]['X-MediaBrowser-Token'] = $this->token;
        } else {
            $opts[RequestOptions::HEADERS]['X-Emby-Authorization'] = sprintf(
                'MediaBrowser Client="%s", Device="script", DeviceId="", Version="%s", Token="%s"',
                Config::get('name'),
                Config::get('version'),
                $this->token
            );
        }

        return $opts;
    }

    protected function getLibraries(Closure $ok, Closure $error): array
    {
        if (!($this->url instanceof Uri)) {
            throw new RuntimeException('No host was set.');
        }

        if (null === $this->token) {
            throw new RuntimeException('No token was set.');
        }

        if (null === $this->user) {
            throw new RuntimeException('No User was set.');
        }

        try {
            $this->logger->debug(
                sprintf('Requesting libraries From %s.', $this->name),
                ['url' => $this->url->getHost()]
            );

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'Recursive' => 'false',
                        'Fields' => 'ProviderIds',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                    ]
                )
            );

            $response = $this->http->request('GET', $url, $this->getHeaders());

            $content = $response->getBody()->getContents();

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

            $listDirs = ag($json, 'Items', []);

            if (empty($listDirs)) {
                $this->logger->notice(sprintf('No libraries found at %s.', $this->name));
                Data::add($this->name, 'no_import_update', true);
                return [];
            }
        } catch (GuzzleException $e) {
            $this->logger->error($e->getMessage());
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
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', $this->options['ignore']));
        }

        $promises = [];
        $ignored = $unsupported = 0;

        foreach ($listDirs as $section) {
            $key = (string)ag($section, 'Id');
            $title = ag($section, 'Name', '???');
            $type = ag($section, 'CollectionType', 'unknown');

            if ('movies' !== $type && 'tvshows' !== $type) {
                $unsupported++;
                $this->logger->debug(sprintf('Skipping %s library - %s. Not supported type.', $this->name, $title));

                continue;
            }

            $type = $type === 'movies' ? StateEntity::TYPE_MOVIE : StateEntity::TYPE_EPISODE;
            $cName = sprintf('(%s) - (%s:%s)', $title, $type, $key);

            if (null !== $ignoreIds && in_array($key, $ignoreIds, true)) {
                $ignored++;
                $this->logger->notice(
                    sprintf('Skipping %s library - %s. Ignored by user config option.', $this->name, $cName)
                );
                continue;
            }

            $url = $this->url->withPath(sprintf('/Users/%s/items/', $this->user))->withQuery(
                http_build_query(
                    [
                        'parentId' => $key,
                        'recursive' => 'true',
                        'enableUserData' => 'true',
                        'enableImages' => 'false',
                        'includeItemTypes' => 'Movie,Episode',
                        'Fields' => 'ProviderIds,DateCreated,OriginalTitle,SeasonUserData,DateLastSaved',
                    ]
                )
            );

            $this->logger->debug(sprintf('Requesting %s - %s library content.', $this->name, $cName), ['url' => $url]);

            $promises[] = $this->http->requestAsync('GET', $url, $this->getHeaders())->then(
                $ok($cName, $type, $url),
                $error($cName, $type, $url)
            );
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
                        $content = $response->getBody()->getContents();

                        $this->logger->debug(
                            sprintf('===[ Sample from %s - %s - response ]===', $this->name, $cName)
                        );
                        $this->logger->debug(!empty($content) ? mb_substr($content, 0, 200) : '***EMPTY***');
                        $this->logger->debug('===[ End ]===');

                        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

                        unset($content);
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

                    $this->processImport($mapper, $type, $cName, $payload['Items'] ?? [], $after);
                };
            },
            function (string $cName, string $type, Uri|string $url) {
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
            function (string $cName, string $type) use ($mapper) {
                return function (ResponseInterface $response) use ($mapper, $cName, $type) {
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
                        $content = $response->getBody()->getContents();

                        $this->logger->debug(
                            sprintf('===[ Sample from %s - %s - response ]===', $this->name, $cName)
                        );
                        $this->logger->debug(!empty($content) ? mb_substr($content, 0, 200) : '***EMPTY***');
                        $this->logger->debug('===[ End ]===');

                        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

                        unset($content);
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

                    $this->processExport($mapper, $type, $cName, $payload['Items'] ?? []);
                };
            },
            function (string $cName, string $type, Uri|string $url) {
                return fn(Throwable $e) => $this->logger->error(
                    sprintf('Request to %s - %s - failed. Reason: \'%s\'.', $this->name, $cName, $e->getMessage()),
                    ['url' => $url]
                );
            }
        );
    }

    protected function processExport(ExportInterface $mapper, string $type, string $library, array $items): void
    {
        $x = 0;
        $total = count($items);
        Data::increment($this->name, $type . '_total', $total);

        foreach ($items as $item) {
            try {
                $x++;

                if (StateEntity::TYPE_MOVIE === $type) {
                    $iName = sprintf(
                        '%s - %s - [%s (%d)]',
                        $this->name,
                        $library,
                        $item['Name'] ?? $item['OriginalTitle'] ?? '??',
                        $item['ProductionYear'] ?? 0000
                    );
                } else {
                    $iName = trim(
                        sprintf(
                            '%s - %s - [%s - (%dx%d) - %s]',
                            $this->name,
                            $library,
                            $item['SeriesName'] ?? '??',
                            $item['ParentIndexNumber'] ?? 0,
                            $item['IndexNumber'] ?? 0,
                            $item['Name'] ?? ''
                        )
                    );
                }

                if (!$this->hasSupportedIds($item['ProviderIds'] ?? [])) {
                    $this->logger->debug(
                        sprintf('(%d/%d) Ignoring %s. No supported guid.', $total, $x, $iName),
                        $item['ProviderIds'] ?? []
                    );
                    Data::increment($this->name, $type . '_ignored_no_supported_guid');
                    continue;
                }

                $date = $item['UserData']['LastPlayedDate'] ?? $item['DateCreated'] ?? $item['PremiereDate'] ?? null;

                if (null === $date) {
                    $this->logger->error(sprintf('(%d/%d) Ignoring %s. No date is set.', $total, $x, $iName));
                    Data::increment($this->name, $type . '_ignored_no_date_is_set');
                    continue;
                }

                $date = makeDate($date);

                $guids = self::getGuids($type, $item['ProviderIds'] ?? []);

                if (null === ($entity = $mapper->findByIds($guids))) {
                    $this->logger->debug(
                        sprintf('(%d/%d) Ignoring %s. Not found in db.', $total, $x, $iName),
                        $item['ProviderIds'] ?? []
                    );
                    Data::increment($this->name, $type . '_ignored_not_found_in_db');
                    continue;
                }

                if ($date->getTimestamp() > $entity->updated) {
                    $this->logger->debug(
                        sprintf('(%d/%d) Ignoring %s. Date is newer then what in db.', $total, $x, $iName)
                    );
                    Data::increment($this->name, $type . '_ignored_date_is_newer');

                    continue;
                }

                $isWatched = (int)($item['UserData']['Played'] ?? false);

                if ($isWatched === $entity->watched) {
                    $this->logger->debug(
                        sprintf('(%d/%d) Ignoring %s. State is unchanged.', $total, $x, $iName)
                    );
                    Data::increment($this->name, $type . '_ignored_state_unchanged');
                    continue;
                }

                $this->logger->debug(sprintf('(%d/%d) Queuing %s.', $total, $x, $iName), ['url' => $this->url]);

                $mapper->queue(
                    new \GuzzleHttp\Psr7\Request(
                        1 === $entity->watched ? 'POST' : 'DELETE',
                        $this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, $item['Id'])),
                        $this->getHeaders()['headers'] ?? []
                    )
                );
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    protected function processImport(
        ImportInterface $mapper,
        string $type,
        string $library,
        array $items,
        DateTimeInterface|null $after = null
    ): void {
        $x = 0;
        $total = count($items);
        Data::increment($this->name, $type . '_total', $total);

        foreach ($items as $item) {
            try {
                $x++;

                if (StateEntity::TYPE_MOVIE === $type) {
                    $iName = sprintf(
                        '%s - %s - [%s (%d)]',
                        $this->name,
                        $library,
                        $item['Name'] ?? $item['OriginalTitle'] ?? '??',
                        $item['ProductionYear'] ?? 0000
                    );
                } else {
                    $iName = trim(
                        sprintf(
                            '%s - %s - [%s - (%dx%d) - %s]',
                            $this->name,
                            $library,
                            $item['SeriesName'] ?? '??',
                            $item['ParentIndexNumber'] ?? 0,
                            $item['IndexNumber'] ?? 0,
                            $item['Name'] ?? ''
                        )
                    );
                }

                if (!$this->hasSupportedIds($item['ProviderIds'] ?? [])) {
                    $this->logger->debug(
                        sprintf('(%d/%d) Ignoring %s. No supported guid.', $total, $x, $iName),
                        $item['ProviderIds'] ?? []
                    );
                    Data::increment($this->name, $type . '_ignored_no_supported_guid');
                    continue;
                }

                $date = $item['UserData']['LastPlayedDate'] ?? $item['DateCreated'] ?? $item['PremiereDate'] ?? null;

                if (null === $date) {
                    $this->logger->error(sprintf('(%d/%d) Ignoring %s. No date is set.', $total, $x, $iName));
                    Data::increment($this->name, $type . '_ignored_no_date_is_set');
                    continue;
                }

                $updatedAt = makeDate($date)->getTimestamp();

                if ($after !== null && $after->getTimestamp() >= $updatedAt) {
                    $this->logger->debug(
                        sprintf('(%d/%d) Ignoring %s. Not played since last sync.', $total, $x, $iName)
                    );
                    Data::increment($this->name, $type . '_ignored_not_played_since_last_sync');

                    continue;
                }

                $this->logger->debug(sprintf('(%d/%d) Processing %s.', $total, $x, $iName), ['url' => $this->url]);
                if (StateEntity::TYPE_MOVIE === $type) {
                    $meta = [
                        'via' => $this->name,
                        'title' => $item['Name'] ?? $item['OriginalTitle'] ?? '??',
                        'year' => $item['ProductionYear'] ?? 0000,
                        'date' => makeDate($item['PremiereDate'] ?? $item['ProductionYear'] ?? 'now')->format('Y-m-d'),
                    ];
                } else {
                    $meta = [
                        'via' => $this->name,
                        'series' => $item['SeriesName'] ?? '??',
                        'year' => $item['ProductionYear'] ?? 0000,
                        'season' => $item['ParentIndexNumber'] ?? 0,
                        'episode' => $item['IndexNumber'] ?? 0,
                        'title' => $item['Name'] ?? '',
                        'date' => makeDate($item['PremiereDate'] ?? $item['ProductionYear'] ?? 'now')->format('Y-m-d'),
                    ];
                }

                $row = [
                    'type' => $type,
                    'updated' => $updatedAt,
                    'watched' => (int)($item['UserData']['Played'] ?? false),
                    'meta' => $meta,
                    ...self::getGuids($type, $item['ProviderIds'] ?? []),
                ];

                $mapper->add($this->name, new StateEntity($row));
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    protected static function getGuids(string $type, array $ids): array
    {
        $guid = [];

        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
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

    protected function hasSupportedIds(array $ids): bool
    {
        $ids = array_change_key_case($ids, CASE_LOWER);

        foreach ($ids as $key => $value) {
            if (null !== (self::GUID_MAPPER[$key] ?? null) && !empty($value)) {
                return true;
            }
        }

        return false;
    }

    public function setState(string $name, Uri $url, string|int|null $token = null, array $opts = []): ServerInterface
    {
        if (true === $this->loaded) {
            throw new RuntimeException('setState: already called once');
        }

        $this->name = $name;
        $this->url = $url;
        $this->token = $token;
        $this->user = $opts['user'] ?? null;
        if (null !== ($opts['user'] ?? null)) {
            unset($opts['user']);
        }

        $this->isEmby = (bool)($opts['emby'] ?? false);

        if (null !== ($opts['emby'] ?? null)) {
            unset($opts['emby']);
        }

        $this->options = $opts;
        $this->loaded = true;

        return $this;
    }

    protected static function afterString(string $subject, string $search): string
    {
        return empty($search) ? $subject : array_reverse(explode($search, $subject, 2))[0];
    }
}
