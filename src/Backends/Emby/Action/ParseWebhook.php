<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Emby\EmbyActionTrait;
use App\Backends\Emby\EmbyClient;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class ParseWebhook
{
    use CommonTrait;
    use EmbyActionTrait;
    use JellyfinActionTrait;

    protected const WEBHOOK_ALLOWED_TYPES = [
        EmbyClient::TYPE_MOVIE,
        EmbyClient::TYPE_EPISODE,
    ];

    protected const WEBHOOK_ALLOWED_EVENTS = [
        'item.markplayed',
        'item.markunplayed',
        'playback.scrobble',
        'playback.pause',
        'playback.unpause',
        'playback.start',
        'playback.stop',
        'library.new',
    ];

    protected const WEBHOOK_TAINTED_EVENTS = [
        'playback.pause',
        'playback.unpause',
        'playback.start',
        'library.new'
    ];

    /**
     * Parse Plex Webhook payload.
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

        $event = ag($json, 'Event', 'unknown');
        $type = ag($json, 'Item.Type', 'not_found');
        $id = ag($json, 'Item.Id');

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

        try {
            $obj = $this->getItemDetails(context: $context, id: $id);

            if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
                $isPlayed = 1;
                $lastPlayedAt = time();
            } elseif ('item.markunplayed' === $event) {
                $isPlayed = 0;
                $lastPlayedAt = makeDate(ag($json, 'Item.DateCreated'))->getTimestamp();
            } else {
                $isPlayed = (int)(bool)ag(
                    $json,
                    [
                        'Item.Played',
                        'Item.PlayedToCompletion',
                        'PlaybackInfo.PlayedToCompletion',
                    ],
                    false
                );

                $lastPlayedAt = (0 === $isPlayed) ? makeDate(ag($json, 'Item.DateCreated'))->getTimestamp() : time();
            }

            $logContext = [
                'item' => [
                    'id' => ag($obj, 'Id'),
                    'type' => ag($obj, 'Type'),
                    'title' => match (ag($obj, 'Type')) {
                        EmbyClient::TYPE_MOVIE => sprintf(
                            '%s (%s)',
                            ag($obj, ['Name', 'OriginalTitle'], '??'),
                            ag($obj, 'ProductionYear', '0000')
                        ),
                        EmbyClient::TYPE_EPISODE => trim(
                            sprintf(
                                '%s - (%sx%s)',
                                ag($obj, 'SeriesName', '??'),
                                str_pad((string)ag($obj, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                                str_pad((string)ag($obj, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                            )
                        ),
                        default => throw new InvalidArgumentException(
                            r('Unexpected Content type [{type}] was received.', [
                                'type' => $type
                            ])
                        ),
                    },
                    'year' => ag($obj, 'ProductionYear'),
                ],
            ];

            $disableGuid = (bool)Config::get('episodes.disable.guid');

            if (EmbyClient::TYPE_EPISODE === $type && true === $disableGuid) {
                $guids = [];
            } else {
                $guids = $guid->get(guids: ag($json, 'Item.ProviderIds', []), context: $logContext);
            }

            $fields = [
                iState::COLUMN_GUIDS => $guids,
                iState::COLUMN_META_DATA => [
                    $context->backendName => [
                        iState::COLUMN_GUIDS => $guid->parse(
                            guids: ag($json, 'Item.ProviderIds', []),
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

            if (false === in_array($event, self::WEBHOOK_TAINTED_EVENTS)) {
                $fields = array_replace_recursive($fields, [
                    iState::COLUMN_WATCHED => $isPlayed,
                    iState::COLUMN_UPDATED => $lastPlayedAt,
                    iState::COLUMN_META_DATA => [
                        $context->backendName => [
                            iState::COLUMN_WATCHED => (string)$isPlayed,
                            iState::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ]);
            }

            if (null !== ($path = ag($json, 'Item.Path'))) {
                $fields[iState::COLUMN_META_DATA][$context->backendName][iState::COLUMN_META_PATH] = $path;
            }

            if (false === $isPlayed && null !== ($progress = ag($json, 'PlaybackInfo.PositionTicks', null))) {
                $fields[iState::COLUMN_META_DATA][$context->backendName][iState::COLUMN_META_DATA_PROGRESS] = (string)floor(
                    $progress / 1_00_00
                ); // -- Convert to milliseconds.
            }

            $entity = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $obj,
                opts: ['override' => $fields, Options::DISABLE_GUID => $disableGuid],
            )->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS));

            if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: 'Ignoring [{backend}] [{title}] webhook event. No valid/supported external ids.',
                        context: [
                            'backend' => $context->backendName,
                            'title' => $entity->getName(),
                            'context' => [
                                'attributes' => $request->getAttributes(),
                                'parsed' => $entity->getAll(),
                                'payload' => $request->getParsedBody(),
                            ],
                        ],
                        level: Levels::ERROR
                    ),
                    extra: [
                        'http_code' => 200,
                        'message' => $context->backendName . ': Import ignored. No valid/supported external ids.'
                    ],
                );
            }

            return new Response(status: true, response: $entity);
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] webhook event parsing. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
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
                        'trace' => $context->trace ? $e->getTrace() : [],
                    ],
                    level: Levels::ERROR
                ),
                extra: [
                    'http_code' => 200,
                    'message' => $context->backendName . ': Failed to handle payload. Check logs.'
                ],
            );
        }
    }
}
