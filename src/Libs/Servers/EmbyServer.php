<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
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
        array $persist = [],
        array $options = []
    ): ServerInterface {
        $options['emby'] = true;

        return (new self($this->http, $this->logger, $this->cache))->setState(
            $name,
            $url,
            $token,
            $userId,
            $persist,
            $options
        );
    }

    public static function parseWebhook(ServerRequestInterface $request): StateInterface
    {
        $payload = ag($request->getParsedBody(), 'data', null);

        if (null === $payload || null === ($json = json_decode((string)$payload, true))) {
            throw new HttpException('No payload.', 400);
        }

        $via = str_replace(' ', '_', ag($json, 'Server.Name', 'Webhook'));
        $event = ag($json, 'Event', 'unknown');
        $type = ag($json, 'Item.Type', 'not_found');

        if (true === Config::get('webhook.debug')) {
            saveWebhookPayload($request, "emby.{$via}.{$event}", $json);
        }

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(afterLast(__CLASS__, '\\') . ': ' . sprintf('Not allowed Type [%s]', $type), 200);
        }

        $type = strtolower($type);

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(sprintf('Not allowed Event [%s]', $event), 200);
        }

        if (null === ($date = ag($json, 'Item.DateCreated', null))) {
            throw new HttpException('No DateCreated value is set.', 200);
        }

        $meta = match ($type) {
            StateInterface::TYPE_MOVIE => [
                'via' => $via,
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
                'via' => $via,
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
            default => throw new HttpException('Invalid content type.', 400),
        };

        if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
            $isWatched = 1;
        } elseif ('item.markunplayed' === $event) {
            $isWatched = 0;
        } else {
            $isWatched = (int)(bool)ag($json, 'Item.Played', ag($json, 'Item.PlayedToCompletion', 0));
        }

        $row = [
            'type' => $type,
            'updated' => makeDate($date)->getTimestamp(),
            'watched' => $isWatched,
            'meta' => $meta,
            ...self::getGuids($type, ag($json, 'Item.ProviderIds', []))
        ];

        return Container::get(StateInterface::class)::fromArray($row)->setIsTainted(
            in_array($event, self::WEBHOOK_TAINTED_EVENTS)
        );
    }

    public function pushStates(array $entities, DateTimeInterface|null $after = null): array
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
                    if (false === preg_match('#(.+?)://\w+?/(.+)#s', $pointer, $matches)) {
                        continue;
                    }
                    $guids[] = sprintf('%s.%s', $matches[1], $matches[2]);
                }

                if (empty($guids)) {
                    continue;
                }

                $url = $this->url->withPath(sprintf('/Users/%s/items', $this->user))->withQuery(
                    http_build_query(
                        [
                            'Recursive' => 'true',
                            'Fields' => 'ProviderIds,DateCreated',
                            'enableUserData' => 'true',
                            'enableImages' => 'false',
                            'AnyProviderIdEquals' => implode(',', $guids),
                        ]
                    )
                );

                $requests[] = $this->http->request(
                    'GET',
                    (string)$url,
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

                $date = ag($json, 'UserData.LastPlayedDate', ag($json, 'DateCreated', ag($json, 'PremiereDate', null)));

                if (null === $date) {
                    $this->logger->notice(sprintf('Ignoring %s. No date is set.', $iName));
                    continue;
                }

                $date = strtotime($date);

                if ($state->watched === $isWatched) {
                    $this->logger->debug(sprintf('Ignoring %s. State is unchanged.', $iName));
                    continue;
                }

                if (false === ($this->options[ServerInterface::OPT_EXPORT_IGNORE_DATE] ?? false)) {
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
                $this->logger->error($e->getMessage());
            }
        }

        unset($requests);

        return $stateRequests;
    }

}
