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
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Options;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\Log\LoggerInterface as iLogger;
use Throwable;

/**
 * Class ParseWebhook
 *
 * This class is responsible for parsing a webhook payload from Emby backend.
 */
final class ParseWebhook
{
    use CommonTrait;
    use EmbyActionTrait;
    use JellyfinActionTrait;

    /**
     * @var string $action Action name
     */
    protected string $action = 'emby.parseWebhook';

    /**
     * @var array<string> Supported entity types.
     */
    protected const array WEBHOOK_ALLOWED_TYPES = [
        EmbyClient::TYPE_MOVIE,
        EmbyClient::TYPE_EPISODE,
    ];

    /**
     * @var array<string> Supported webhook events.
     */
    protected const array WEBHOOK_ALLOWED_EVENTS = [
        'item.markplayed',
        'item.markunplayed',
        'playback.scrobble',
        'playback.pause',
        'playback.unpause',
        'playback.start',
        'playback.stop',
        'library.new',
    ];

    /**
     * @var array<string> Events that should be marked as tainted.
     */
    protected const array WEBHOOK_TAINTED_EVENTS = [
        'playback.pause',
        'playback.unpause',
        'playback.start',
        'library.new'
    ];

    public function __construct(private iLogger $logger)
    {
    }

    /**
     * Wrap the parser in try response block.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The guid object.
     * @param iRequest $request The request object.
     *
     * @return Response The response object.
     */
    public function __invoke(Context $context, iGuid $guid, iRequest $request): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->parse($context, $guid, $request),
            action: $this->action,
        );
    }

    /**
     * Parse the Emby webhook payload.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The guid object.
     * @param iRequest $request The request object.
     *
     * @return Response The response object.
     */
    private function parse(Context $context, iGuid $guid, iRequest $request): Response
    {
        $logContext = [
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ];

        if (null === ($json = $request->getParsedBody())) {
            return new Response(status: false, extra: [
                'http_code' => Status::BAD_REQUEST->value,
                'message' => r(
                    text: "Ignoring '{client}: {user}@{backend}' request. Invalid request, no payload.",
                    context: $logContext
                ),
            ]);
        }

        $event = ag($json, 'Event', 'unknown');
        $type = ag($json, 'Item.Type', 'not_found');
        $id = ag($json, 'Item.Id');

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            return new Response(status: false, extra: [
                'http_code' => Status::OK->value,
                'message' => r(
                    text: "{user}@{backend}: Webhook content type '{type}' is not supported.",
                    context: [...$logContext, 'type' => $type]
                )
            ]);
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            return new Response(status: false, extra: [
                'http_code' => Status::OK->value,
                'message' => r(
                    text: "{user}@{backend}: Webhook event type '{event}' is not supported.",
                    context: [...$logContext, 'event' => $event]
                )
            ]);
        }

        if (null === $id) {
            return new Response(status: false, extra: [
                'http_code' => Status::BAD_REQUEST->value,
                'message' => r('{user}@{backend}: No item id was found in body.', $logContext),
            ]);
        }

        try {
            $resp = Container::get(GetMetaData::class)(context: $context, id: $id);
            if (!$resp->isSuccessful()) {
                return $resp;
            } else {
                $obj = $resp->response;
            }

            if ('item.markplayed' === $event || 'playback.scrobble' === $event) {
                $isPlayed = 1;
                $lastPlayedAt = time();
            } elseif ('item.markunplayed' === $event) {
                $isPlayed = 0;
                $lastPlayedAt = makeDate(ag($json, 'Item.DateCreated'))->getTimestamp();
            } else {
                $isPlayed = (int)(bool)ag($json, [
                    'Item.Played',
                    'Item.PlayedToCompletion',
                    'PlaybackInfo.PlayedToCompletion',
                ], false);

                $lastPlayedAt = (0 === $isPlayed) ? makeDate(ag($json, 'Item.DateCreated'))->getTimestamp() : time();
            }

            $logContext = [
                ...$logContext,
                'item' => [
                    'id' => ag($obj, 'Id'),
                    'type' => ag($obj, 'Type'),
                    'title' => match (ag($obj, 'Type')) {
                        EmbyClient::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($obj, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($obj, 'ProductionYear', '0000')
                        ]),
                        EmbyClient::TYPE_EPISODE => trim(
                            r('{title} - ({season}x{episode})', [
                                'title' => ag($obj, 'SeriesName', '??'),
                                'season' => str_pad((string)ag($obj, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                                'episode' => str_pad((string)ag($obj, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                            ])
                        ),
                        default => throw new InvalidArgumentException(
                            r('[{client}: {backend}] Unexpected Content type [{type}] was received.', [
                                'client' => $context->clientName,
                                'backend' => $context->backendName,
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

            $allowUpdate = (int)Config::get('progress.threshold', 0);
            $progCheck = $allowUpdate || 0 === $isPlayed;

            if ($progCheck && null !== ($progress = ag($json, 'PlaybackInfo.PositionTicks', null))) {
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
                        message: "{action}: Ignoring '{client}: {user}@{backend}' - '{title}' webhook event. No valid/supported external ids.",
                        context: [
                            'title' => $entity->getName(),
                            ...$logContext,
                            'context' => [
                                'attributes' => $request->getAttributes(),
                                'parsed' => $entity->getAll(),
                                'payload' => $request->getParsedBody(),
                            ],
                        ],
                        level: Levels::ERROR
                    ),
                    extra: [
                        'http_code' => Status::OK->value,
                        'message' => r("{user}@{backend}: No valid/supported external ids.", $logContext)
                    ],
                );
            }

            return new Response(status: true, response: $entity);
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' webhook event parsing. {error.message} at '{error.file}:{error.line}'.",
                    context: [
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        ...$logContext,
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                        'context' => [
                            'attributes' => $request->getAttributes(),
                            'payload' => $request->getParsedBody(),
                        ],
                    ],
                    level: Levels::ERROR,
                    previous: $e
                ),
                extra: [
                    'http_code' => Status::OK->value,
                    'message' => r("{user}@{backend}: Failed to process event check logs.", [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'user' => $context->userContext->name,
                    ])
                ],
            );
        }
    }
}
