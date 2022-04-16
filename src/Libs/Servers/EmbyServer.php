<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use DateTimeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

class EmbyServer extends JellyfinServer
{
    protected const WEBHOOK_ALLOWED_TYPES = [
        'Movie',
        'Episode',
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'item.markplayed',
        'item.markunplayed',
        'playback.scrobble',
        'playback.pause',
        'playback.start',
        'playback.stop',
    ];

    protected const WEBHOOK_TAINTED_EVENTS = [
        'playback.pause',
        'playback.start',
        'playback.stop',
    ];

    public function setUp(
        string $name,
        UriInterface $url,
        string|int|null $token = null,
        string|int|null $userId = null,
        string|int|null $uuid = null,
        array $persist = [],
        array $options = []
    ): ServerInterface {
        $options['emby'] = true;

        return parent::setUp($name, $url, $token, $userId, $uuid, $persist, $options);
    }

    public static function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

        if (false === Config::get('webhook.debug', false) && !str_starts_with($userAgent, 'Emby Server/')) {
            return $request;
        }

        $payload = ag($request->getParsedBody() ?? [], 'data', null);

        if (null === $payload || null === ($json = json_decode((string)$payload, true))) {
            return $request;
        }

        $attributes = [
            'SERVER_ID' => ag($json, 'Server.Id', ''),
            'SERVER_NAME' => ag($json, 'Server.Name', ''),
            'SERVER_VERSION' => afterLast($userAgent, '/'),
            'USER_ID' => ag($json, 'User.Id', ''),
            'USER_NAME' => ag($json, 'User.Name', ''),
        ];

        foreach ($attributes as $key => $val) {
            $request = $request->withAttribute($key, $val);
        }

        return $request;
    }

    public function parseWebhook(ServerRequestInterface $request): StateInterface
    {
        $payload = ag($request->getParsedBody() ?? [], 'data', null);

        if (null === $payload || null === ($json = json_decode((string)$payload, true))) {
            throw new HttpException(sprintf('%s: No payload.', afterLast(__CLASS__, '\\')), 400);
        }

        $event = ag($json, 'Event', 'unknown');
        $type = ag($json, 'Item.Type', 'not_found');

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(sprintf('%s: Not allowed type [%s]', afterLast(__CLASS__, '\\'), $type), 200);
        }

        $type = strtolower($type);

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('%s: Not allowed event [%s]', afterLast(__CLASS__, '\\'), $event), 200);
        }

        $date = time();

        $meta = match ($type) {
            StateInterface::TYPE_MOVIE => [
                'via' => $this->name,
                'title' => ag($json, 'Item.Name', ag($json, 'Item.OriginalTitle', '??')),
                'year' => ag($json, 'Item.ProductionYear', 0000),
                'date' => makeDate(
                    ag(
                        $json,
                        'Item.PremiereDate',
                        ag($json, 'Item.ProductionYear', ag($json, 'Item.DateCreated', 'now'))
                    )
                )->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            StateInterface::TYPE_EPISODE => [
                'via' => $this->name,
                'series' => ag($json, 'Item.SeriesName', '??'),
                'year' => ag($json, 'Item.ProductionYear', 0000),
                'season' => ag($json, 'Item.ParentIndexNumber', 0),
                'episode' => ag($json, 'Item.IndexNumber', 0),
                'title' => ag($json, 'Item.Name', ag($json, 'Item.OriginalTitle', '??')),
                'date' => makeDate(ag($json, 'Item.PremiereDate', ag($json, 'Item.ProductionYear', 'now')))->format(
                    'Y-m-d'
                ),
                'webhook' => [
                    'event' => $event,
                ],
            ],
            default => throw new HttpException(sprintf('%s: Invalid content type.', afterLast(__CLASS__, '\\')), 400),
        };

        if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
            $isWatched = 1;
        } elseif ('item.markunplayed' === $event) {
            $isWatched = 0;
        } else {
            $isWatched = (int)(bool)ag($json, 'Item.Played', ag($json, 'Item.PlayedToCompletion', 0));
        }

        $guids = ag($json, 'Item.ProviderIds', []);

        if (!$this->hasSupportedIds($guids)) {
            throw new HttpException(
                sprintf('%s: No supported GUID was given. [%s]', afterLast(__CLASS__, '\\'), arrayToString($guids)),
                400
            );
        }

        $guids = $this->getGuids($type, $guids);

        foreach (Guid::fromArray($guids)->getPointers() as $guid) {
            $this->cacheData[$guid] = ag($json, 'Item.Id');
        }

        $row = [
            'type' => $type,
            'updated' => $date,
            'watched' => $isWatched,
            'meta' => $meta,
            ...$guids
        ];

        if (true === Config::get('webhook.debug') || null !== ag($request->getQueryParams(), 'debug')) {
            saveWebhookPayload($request, "{$this->name}.{$event}", $json + ['entity' => $row]);
        }

        return Container::get(StateInterface::class)::fromArray($row)->setIsTainted(
            in_array($event, self::WEBHOOK_TAINTED_EVENTS)
        );
    }

    public function push(array $entities, DateTimeInterface|null $after = null): array
    {
        $requests = [];

        foreach ($entities as &$entity) {
            if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                if (null !== $after && $after->getTimestamp() > $entity->updated) {
                    $entity = null;
                    continue;
                }
            }

            $entity->plex_guid = null;
        }

        unset($entity);

        /** @var StateInterface $entity */
        foreach ($entities as $entity) {
            if (null === $entity || false === $entity->hasGuids()) {
                continue;
            }

            try {
                $guids = [];

                foreach ($entity->getPointers() as $pointer) {
                    if (str_starts_with($pointer, 'guid_plex://')) {
                        continue;
                    }
                    if (false === preg_match('#guid_(.+?)://\w+?/(.+)#s', $pointer, $matches)) {
                        continue;
                    }
                    $guids[] = sprintf('%s.%s', $matches[1], $matches[2]);
                }

                if (empty($guids)) {
                    continue;
                }

                $requests[] = $this->http->request(
                    'GET',
                    (string)$this->url->withPath(sprintf('/Users/%s/items', $this->user))->withQuery(
                        http_build_query(
                            [
                                'Recursive' => 'true',
                                'Fields' => 'ProviderIds,DateCreated',
                                'enableUserData' => 'true',
                                'enableImages' => 'false',
                                'AnyProviderIdEquals' => implode(',', $guids),
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
                $this->logger->error($e->getMessage());
            }
        }

        $stateRequests = [];

        foreach ($requests as $response) {
            try {
                $json = ag(
                        json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
                        'Items',
                        []
                    )[0] ?? [];

                $state = $response->getInfo('user_data')['state'];
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

                $isWatched = (int)(bool)ag($json, 'UserData.Played', false);


                if ($state->watched === $isWatched) {
                    $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                    continue;
                }

                if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
                    $date = ag(
                        $json,
                        'UserData.LastPlayedDate',
                        ag($json, 'DateCreated', ag($json, 'PremiereDate', null))
                    );

                    if (null === $date) {
                        $this->logger->notice(sprintf('Ignoring %s. No date is set.', $iName));
                        continue;
                    }

                    $date = strtotime($date);

                    if ($date >= $state->updated) {
                        $this->logger->debug(sprintf('Ignoring %s. Date is newer then what in db.', $iName));
                        continue;
                    }
                }

                $stateRequests[] = $this->http->request(
                    1 === $state->watched ? 'POST' : 'DELETE',
                    (string)$this->url->withPath(sprintf('/Users/%s/PlayedItems/%s', $this->user, ag($json, 'Id'))),
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

}
