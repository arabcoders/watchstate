<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Common\Context;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Libs\Entity\StateInterface as iFace;
use App\Libs\Guid;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class ParseWebhook
{
    use CommonTrait, PlexActionTrait;

    protected const WEBHOOK_ALLOWED_TYPES = [
        PlexClient::TYPE_MOVIE,
        PlexClient::TYPE_EPISODE,
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

    /**
     * Parse Plex Webhook payload.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param iRequest $request
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, iGuid $guid, iRequest $request, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->parse($context, $guid, $request, $opts),
        );
    }

    private function parse(Context $context, iGuid $guid, iRequest $request): Response
    {
        if (null === ($json = $request->getParsedBody())) {
            return new Response(status: false, extra: [
                'http_code' => 400,
                'message' => $context->clientName . ': No payload.'
            ]);
        }

        $item = ag($json, 'Metadata', []);
        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);
        $id = ag($item, 'ratingKey');

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            return new Response(status: false, extra: [
                'http_code' => 200,
                'message' => sprintf('%s: Webhook content type [%s] is not supported.', $context->backendName, $type)
            ]);
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            return new Response(status: false, extra: [
                'http_code' => 200,
                'message' => sprintf('%s: Webhook event type [%s] is not supported.', $context->backendName, $event)
            ]);
        }

        if (null === $id) {
            return new Response(status: false, extra: [
                'http_code' => 400,
                'message' => $context->backendName . ': No item id was found in body.'
            ]);
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        if (null !== $ignoreIds && in_array(ag($item, 'librarySectionID', '???'), $ignoreIds)) {
            return new Response(status: false, extra: [
                'http_code' => 200,
                'message' => $context->backendName . ': library is ignored by user config.'
            ]);
        }

        try {
            $isPlayed = (bool)ag($item, 'viewCount', false);
            $lastPlayedAt = true === $isPlayed ? ag($item, 'lastViewedAt') : null;

            $fields = [
                iFace::COLUMN_WATCHED => (int)$isPlayed,
                iFace::COLUMN_META_DATA => [
                    $context->backendName => [
                        iFace::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                    ]
                ],
                iFace::COLUMN_EXTRA => [
                    $context->backendName => [
                        iFace::COLUMN_EXTRA_EVENT => $event,
                        iFace::COLUMN_EXTRA_DATE => makeDate('now'),
                    ],
                ],
            ];

            if (true === $isPlayed && null !== $lastPlayedAt) {
                $fields = array_replace_recursive($fields, [
                    iFace::COLUMN_UPDATED => (int)$lastPlayedAt,
                    iFace::COLUMN_META_DATA => [
                        $context->backendName => [
                            iFace::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ]);
            }

            $obj = ag($this->getItemDetails(context: $context, id: $id), 'MediaContainer.Metadata.0', []);

            $guids = $guid->get(ag($item, 'Guid', []), context: [
                'item' => [
                    'id' => ag($item, 'ratingKey'),
                    'type' => ag($item, 'type'),
                    'title' => match ($type) {
                        iFace::TYPE_MOVIE => sprintf(
                            '%s (%s)',
                            ag($item, ['title', 'originalTitle'], '??'),
                            ag($item, 'year', '0000')
                        ),
                        iFace::TYPE_EPISODE => sprintf(
                            '%s - (%sx%s)',
                            ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                            str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                            str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                        ),
                    },
                    'year' => ag($item, ['grandParentYear', 'parentYear', 'year']),
                    'plex_id' => str_starts_with(ag($item, 'guid', ''), 'plex://') ? ag($item, 'guid') : 'none',
                ],
            ]);

            if (count($guids) >= 1) {
                $guids += Guid::makeVirtualGuid($context->backendName, (string)$id);
                $fields[iFace::COLUMN_GUIDS] = $guids;
                $fields[iFace::COLUMN_META_DATA][$context->backendName][iFace::COLUMN_GUIDS] = $fields[iFace::COLUMN_GUIDS];
            }

            $entity = $this->createEntity(
                context: $context,
                guid:    $guid,
                item:    $obj,
                opts:    ['override' => $fields],
            )->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS));

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                return new Response(
                    status: false,
                    error:  new Error(
                                message: 'Ignoring [%(backend)] [%(title)] webhook event. No valid/supported external ids.',
                                context: [
                                             'backend' => $context->backendName,
                                             'title' => $entity->getName(),
                                             'context' => [
                                                 'attributes' => $request->getAttributes(),
                                                 'parsed' => $entity->getAll(),
                                                 'payload' => $request->getParsedBody(),
                                             ],
                                         ],
                                level:   Levels::ERROR
                            ),
                    extra:  [
                                'http_code' => 200,
                                'message' => $context->backendName . ': Import ignored. No valid/supported external ids.'
                            ],
                );
            }

            return new Response(status: true, response: $entity);
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error:  new Error(
                            message: 'Unhandled exception was thrown during [%(backend)] webhook event parsing.',
                            context: [
                                         'backend' => $context->backendName,
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
                                     ],
                            level:   Levels::ERROR
                        ),
                extra:  [
                            'http_code' => 200,
                            'message' => $context->backendName . ': Failed to handle payload. Check logs.'
                        ],
            );
        }
    }
}
