<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface;
use App\Libs\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

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
        array $options = []
    ): ServerInterface {
        $options['emby'] = true;

        return (new self($this->http, $this->logger))->setState($name, $url, $token, $userId, $options);
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
}
