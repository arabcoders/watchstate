<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Plex\PlexActionTrait;
use App\Backends\Plex\PlexClient;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Options;
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
     * Parse Webhook payload.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param iRequest $request
     *
     * @return Response
     */
    public function __invoke(Context $context, iGuid $guid, iRequest $request): Response
    {
        return $this->tryResponse(context: $context, fn: fn() => $this->parse($context, $guid, $request));
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
            $obj = ag($this->getItemDetails(context: $context, id: $id), 'MediaContainer.Metadata.0', []);

            $isPlayed = (bool)ag($item, 'viewCount', false);
            $lastPlayedAt = true === $isPlayed ? ag($item, 'lastViewedAt') : null;

            $year = (int)ag($obj, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($obj, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $logContext = [
                'item' => [
                    'id' => ag($item, 'ratingKey'),
                    'type' => ag($item, 'type'),
                    'title' => match ($type) {
                        iState::TYPE_MOVIE => sprintf(
                            '%s (%s)',
                            ag($item, ['title', 'originalTitle'], '??'),
                            0 === $year ? '0000' : $year,
                        ),
                        iState::TYPE_EPISODE => sprintf(
                            '%s - (%sx%s)',
                            ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                            str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                            str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                        ),
                    },
                    'year' => 0 === $year ? '0000' : $year,
                    'plex_id' => str_starts_with(ag($item, 'guid', ''), 'plex://') ? ag($item, 'guid') : 'none',
                ],
            ];

            $disableGuid = (bool)Config::get('episodes.disable.guid');

            if (PlexClient::TYPE_EPISODE === $type && true === $disableGuid) {
                $guids = [];
            } else {
                $guids = $guid->get(guids: ag($item, 'Guid', []), context: $logContext);
            }

            $guids += Guid::makeVirtualGuid($context->backendName, (string)$id);

            $fields = [
                iState::COLUMN_WATCHED => (int)$isPlayed,
                iState::COLUMN_GUIDS => $guids,
                iState::COLUMN_META_DATA => [
                    $context->backendName => [
                        iState::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                        iState::COLUMN_GUIDS => $guid->parse(
                            guids:   ag($item, 'Guid', []),
                            context: $logContext
                        ),
                    ]
                ],
                iState::COLUMN_EXTRA => [
                    $context->backendName => [
                        iState::COLUMN_EXTRA_EVENT => $event,
                        iState::COLUMN_EXTRA_DATE => makeDate('now'),
                    ],
                ],
            ];

            if (true === $isPlayed && null !== $lastPlayedAt) {
                $fields = array_replace_recursive($fields, [
                    iState::COLUMN_UPDATED => (int)$lastPlayedAt,
                    iState::COLUMN_META_DATA => [
                        $context->backendName => [
                            iState::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ]);
            }

            $entity = $this->createEntity(
                context: $context,
                guid:    $guid,
                item:    $obj,
                opts:    ['override' => $fields, Options::DISABLE_GUID => $disableGuid],
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
                                             'trace' => $context->trace ? $e->getTrace() : [],
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
