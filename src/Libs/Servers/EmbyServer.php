<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Libs\Container;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class EmbyServer extends JellyfinServer
{
    public const NAME = 'EmbyBackend';

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

    public function processRequest(ServerRequestInterface $request, array $opts = []): ServerRequestInterface
    {
        $logger = null;

        try {
            $logger = $opts[LoggerInterface::class] ?? Container::get(LoggerInterface::class);

            $userAgent = ag($request->getServerParams(), 'HTTP_USER_AGENT', '');

            if (false === str_starts_with($userAgent, 'Emby Server/')) {
                return $request;
            }

            $payload = (string)ag($request->getParsedBody() ?? [], 'data', null);

            if (null === ($json = json_decode(json: $payload, associative: true, flags: JSON_INVALID_UTF8_IGNORE))) {
                return $request;
            }

            $request = $request->withParsedBody($json);

            $attributes = [
                'ITEM_ID' => ag($json, 'Item.Id', ''),
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
            $logger?->error(sprintf('%s: %s', self::NAME, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
        }

        return $request;
    }

    /**
     * @param ServerRequestInterface $request
     * @return iFace
     *
     * @link https://emby.media/community/index.php?/topic/96170-webhook-information/&do=findComment&comment=1121860
     */
    public function parseWebhook(ServerRequestInterface $request): iFace
    {
        if (null === ($json = $request->getParsedBody())) {
            throw new HttpException(sprintf('%s: No payload.', afterLast(__CLASS__, '\\')), 400);
        }

        $event = ag($json, 'Event', 'unknown');
        $type = ag($json, 'Item.Type', 'not_found');
        $id = ag($json, 'Item.Id');

        if (null === $type || !in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            throw new HttpException(
                sprintf('%s: Webhook content type is not supported. [%s]', $this->getName(), $type), 200
            );
        }

        if (null === $event || !in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            throw new HttpException(
                sprintf('%s: Webhook event type is not supported. [%s]', $this->getName(), $event), 200
            );
        }

        if (null === $id) {
            throw new HttpException(sprintf('%s: Webhook payload has no id.', $this->getName()), 400);
        }

        $lastPlayedAt = null;
        $type = strtolower($type);

        if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
            $lastPlayedAt = time();
            $isPlayed = 1;
        } elseif ('item.markunplayed' === $event) {
            $isPlayed = 0;
        } else {
            $isPlayed = (int)(bool)ag($json, ['Item.Played', 'Item.PlayedToCompletion'], false);
        }

        try {
            $fields = [
                iFace::COLUMN_EXTRA => [
                    $this->name => [
                        iFace::COLUMN_EXTRA_EVENT => $event,
                        iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                    ],
                ],
            ];

            if (null !== $lastPlayedAt && 1 === $isPlayed) {
                $fields += [
                    iFace::COLUMN_UPDATED => $lastPlayedAt,
                    iFace::COLUMN_WATCHED => $isPlayed,
                    iFace::COLUMN_META_DATA => [
                        $this->name => [
                            iFace::COLUMN_WATCHED => (string)(int)(bool)$isPlayed,
                            iFace::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ];
            }

            $providersId = ag($json, 'Item.ProviderIds', []);

            if (null !== ($guids = $this->getGuids($providersId)) && !empty($guids)) {
                $guids += Guid::makeVirtualGuid($this->name, (string)ag($json, $id));
                $fields[iFace::COLUMN_GUIDS] = $guids;
                $fields[iFace::COLUMN_META_DATA][$this->name][iFace::COLUMN_GUIDS] = $fields[iFace::COLUMN_GUIDS];
            }

            $entity = $this->createEntity(
                item: $this->getMetadata(id: $id),
                type: $type,
                opts: ['override' => $fields],
            )->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS));
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s: %s', self::NAME, $e->getMessage()), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'kind' => get_class($e),
            ]);
            throw new HttpException(
                sprintf(
                    '%s: Unable to process item id \'%s\'.',
                    $this->getName(),
                    $id,
                ), 200
            );
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $message = sprintf('%s: No valid/supported external ids.', self::NAME);

            if (empty($providersId)) {
                $message .= sprintf(' Most likely unmatched %s.', $entity->type);
            }

            $message .= sprintf(' [%s].', arrayToString(['guids' => !empty($providersId) ? $providersId : 'None']));

            throw new HttpException($message, 400);
        }

        return $entity;
    }
}
