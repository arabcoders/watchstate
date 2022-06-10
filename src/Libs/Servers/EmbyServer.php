<?php

declare(strict_types=1);

namespace App\Libs\Servers;

use App\Backends\Emby\Action\InspectRequest;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use App\Libs\HttpException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
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
        return (new InspectRequest(logger: $this->logger))(request: $request);
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
            $this->logger->error('Unhandled exception was thrown during [%(backend)] webhook event parsing.', [
                'backend' => $this->getName(),
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'kind' => get_class($e),
                    'message' => $e->getMessage(),
                ],
                'context' => [
                    'attributes' => $request->getAttributes(),
                    'payload' => $request->getParsedBody(),
                ],
            ]);

            throw new HttpException(
                sprintf('%s: Failed to handle webhook payload check logs.', $this->getName()), 200
            );
        }

        if (!$entity->hasGuids() && !$entity->hasRelativeGuid()) {
            $this->logger->error('Ignoring [%(backend)] [%(title)] webhook event. No valid/supported external ids.', [
                'backend' => $id,
                'title' => $entity->getName(),
                'context' => [
                    'attributes' => $request->getAttributes(),
                    'parsed' => $entity->getAll(),
                    'payload' => $request->getParsedBody(),
                ],
            ]);

            throw new HttpException(
                sprintf('%s: Import ignored. No valid/supported external ids.', $this->getName()),
                200
            );
        }

        return $entity;
    }
}
