<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\Guid;
use App\Libs\HttpException;
use DateTimeInterface;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
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
        try {
            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'Emby Server/')) {
                return $request;
            }

            $payload = ag($request->getParsedBody() ?? [], 'data', null);

            if (null === $payload || null === ($json = json_decode((string)$payload, true))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'SERVER_ID' => ag($json, 'Server.Id', ''),
                'SERVER_NAME' => ag($json, 'Server.Name', ''),
                'SERVER_VERSION' => afterLast($userAgent, '/'),
                'USER_ID' => ag($json, 'User.Id', ''),
                'USER_NAME' => ag($json, 'User.Name', ''),
                'WH_EVENT' => ag($json, 'Event', 'not_set'),
                'WH_TYPE' => ag($json, 'Item.Type', 'not_set'),
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
        if (null === ($json = $request->getParsedBody())) {
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

        $event = strtolower($event);

        $isTainted = in_array($event, self::WEBHOOK_TAINTED_EVENTS);

        if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
            $isWatched = 1;
        } elseif ('item.markunplayed' === $event) {
            $isWatched = 0;
        } else {
            $isWatched = (int)(bool)ag($json, ['Item.Played', 'Item.PlayedToCompletion'], 0);
        }

        $providersId = ag($json, 'Item.ProviderIds', []);

        $row = [
            'type' => $type,
            'updated' => time(),
            'watched' => $isWatched,
            'via' => $this->name,
            'title' => '??',
            'year' => ag($json, 'Item.ProductionYear', 0000),
            'season' => null,
            'episode' => null,
            'parent' => [],
            'guids' => $this->getGuids($providersId),
            'extra' => [
                'date' => makeDate(
                    ag($json, ['Item.PremiereDate', 'Item.ProductionYear', 'Item.DateCreated'], 'now')
                )->format('Y-m-d'),
                'webhook' => [
                    'event' => $event,
                ],
            ],
        ];

        if (StateInterface::TYPE_MOVIE === $type) {
            $row['title'] = ag($json, ['Item.Name', 'Item.OriginalTitle'], '??');
        } elseif (StateInterface::TYPE_EPISODE === $type) {
            $row['title'] = ag($json, 'Item.SeriesName', '??');
            $row['season'] = ag($json, 'Item.ParentIndexNumber', 0);
            $row['episode'] = ag($json, 'Item.IndexNumber', 0);
            $row['extra']['title'] = ag($json, ['Item.Name', 'Item.OriginalTitle'], '??');

            if (null !== ag($json, 'Item.SeriesId')) {
                $row['parent'] = $this->getEpisodeParent(ag($json, 'Item.SeriesId'));
            }
        } else {
            throw new HttpException(sprintf('%s: Invalid content type.', afterLast(__CLASS__, '\\')), 400);
        }

        $entity = Container::get(StateInterface::class)::fromArray($row)->setIsTainted($isTainted);

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $message = sprintf('%s: No valid/supported External ids.', afterLast(__CLASS__, '\\'));

            if (empty($providersId)) {
                $message .= ' Most likely unmatched movie/episode or show.';
            }

            $message .= sprintf(' [%s].', arrayToString(['guids' => !empty($providersId) ? $providersId : 'None']));

            throw new HttpException($message, 400);
        }

        foreach ([...$entity->getRelativePointers(), ...$entity->getPointers()] as $guid) {
            $this->cacheData[$guid] = ag($json, 'Item.Id');
        }

        $savePayload = true === Config::get('webhook.debug') || null !== ag($request->getQueryParams(), 'debug');

        if (false === $isTainted && $savePayload) {
            saveWebhookPayload($this->name . '.' . $event, $request, [
                'entity' => $entity->getAll(),
                'payload' => $json,
            ]);
        }

        return $entity;
    }

    /**
     * @param array $entities
     * @param DateTimeInterface|null $after
     * @return array
     * @TODO need to be updated to support cached items.
     */
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

                foreach ($entity->guids ?? [] as $key => $val) {
                    if ('guid_plex' === $key) {
                        continue;
                    }

                    $guids[] = sprintf('%s.%s', afterLast($key, 'guid_'), $val);
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

    private function getEpisodeParent(int|string $id): array
    {
        if (array_key_exists($id, $this->cacheShow)) {
            return $this->cacheShow[$id];
        }

        try {
            $response = $this->http->request(
                'GET',
                (string)$this->url->withPath(
                    sprintf('/Users/%s/items/' . $id, $this->user)
                ),
                $this->getHeaders()
            );

            if (200 !== $response->getStatusCode()) {
                return [];
            }

            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

            if (null === ($itemType = ag($json, 'Type')) || 'Series' !== $itemType) {
                return [];
            }

            $providersId = (array)ag($json, 'ProviderIds', []);

            if (!$this->hasSupportedIds($providersId)) {
                $this->cacheShow[$id] = [];
                return $this->cacheShow[$id];
            }

            $guids = [];

            foreach (Guid::fromArray($this->getGuids($providersId))->getPointers() as $guid) {
                [$type, $guid] = explode('://', $guid);
                $guids[$type] = $guid;
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
        } catch (Throwable $e) {
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
}
