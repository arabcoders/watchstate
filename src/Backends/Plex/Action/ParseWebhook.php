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
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

final class ParseWebhook
{
    use CommonTrait;
    use PlexActionTrait;

    public const array WEBHOOK_ALLOWED_TYPES = [
        PlexClient::TYPE_MOVIE,
        PlexClient::TYPE_EPISODE,
    ];

    public const array WEBHOOK_ALLOWED_EVENTS = [
        'library.new',
        'library.on.deck',
        'media.play',
        'media.stop',
        'media.resume',
        'media.pause',
        'media.scrobble',
        // -- Tautulli events
        'tautulli.start',
        'tautulli.play',
        'tautulli.stop',
        'tautulli.pause',
        'tautulli.resume',
        'tautulli.watched',
        'tautulli.created',
    ];

    public const array WEBHOOK_TAINTED_EVENTS = [
        'media.play',
        'media.stop',
        'media.resume',
        'media.pause',
        // -- Tautulli events
        'tautulli.start',
        'tautulli.play',
        'tautulli.stop',
        'tautulli.pause',
        'tautulli.resume',
    ];

    public const array WEBHOOK_GENERIC_EVENTS = [
        'library.new',
        'tautulli.created',
    ];

    private string $action = 'plex.parseWebhook';

    /**
     * Parse Webhook payload.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The guid object.
     * @param iRequest $request The request object.
     * @param array $opts Optional options.
     *
     * @return Response The response object.
     */
    public function __invoke(Context $context, iGuid $guid, iRequest $request, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->parse($context, $guid, $request, $opts),
            action: $this->action
        );
    }

    /**
     * Parse webhook payload.
     *
     * @param Context $context The context object.
     * @param iGuid $guid The guid object.
     * @param iRequest $request The request object.
     * @param array $opts Optional options.
     *
     * @return Response The response object.
     */
    private function parse(Context $context, iGuid $guid, iRequest $request, array $opts = []): Response
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

        $item = ag($json, 'Metadata', []);
        $type = ag($json, 'Metadata.type');
        $event = ag($json, 'event', null);
        $id = ag($item, 'ratingKey');

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

        if (empty($id)) {
            return new Response(status: false, extra: [
                'http_code' => Status::BAD_REQUEST->value,
                'message' => r('{user}@{backend}: No item id was found in body.', $logContext),
            ]);
        }

        if (null !== ($ignoreIds = ag($context->options, 'ignore', null))) {
            $ignoreIds = array_map(fn($v) => trim($v), explode(',', (string)$ignoreIds));
        }

        if (null !== $ignoreIds && in_array(ag($item, 'librarySectionID', '???'), $ignoreIds)) {
            return new Response(status: false, extra: [
                'http_code' => Status::OK->value,
                'message' => r('{user}@{backend}: Library id is ignored by user config.', $logContext),
            ]);
        }

        try {
            $obj = ag($this->getItemDetails(context: $context, id: $id, opts: [
                Options::LOG_CONTEXT => ['request' => $json],
                ...$opts
            ]), 'MediaContainer.Metadata.0', []);

            $isPlayed = (bool)ag($item, 'viewCount', false);
            $lastPlayedAt = true === $isPlayed ? ag($item, 'lastViewedAt') : null;

            $year = (int)ag($obj, ['grandParentYear', 'parentYear', 'year'], 0);
            if (0 === $year && null !== ($airDate = ag($obj, 'originallyAvailableAt'))) {
                $year = (int)makeDate($airDate)->format('Y');
            }

            $logContext = [
                ...$logContext,
                'item' => [
                    'id' => ag($item, 'ratingKey'),
                    'type' => ag($item, 'type'),
                    'title' => match ($type) {
                        iState::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($item, ['title', 'originalTitle'], '??'),
                            'year' => 0 === $year ? '0000' : $year,
                        ]),
                        iState::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($item, ['grandparentTitle', 'originalTitle', 'title'], '??'),
                            'season' => str_pad((string)ag($item, 'parentIndex', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string)ag($item, 'index', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r('Unexpected Content type [{type}] was received.', [
                                'type' => $type
                            ])
                        ),
                    },
                    'year' => 0 === $year ? '0000' : $year,
                    'plex_id' => str_starts_with(ag($item, 'guid', ''), 'plex://') ? ag($item, 'guid') : 'none',
                ],
            ];

            $fields = [
                iState::COLUMN_WATCHED => (int)$isPlayed,
                iState::COLUMN_GUIDS => $guid->get(guids: ag($item, 'Guid', []), context: $logContext),
                iState::COLUMN_META_DATA => [
                    $context->backendName => [
                        iState::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                        iState::COLUMN_GUIDS => $guid->parse(
                            guids: ag($item, 'Guid', []),
                            context: $logContext
                        ),
                        iState::COLUMN_META_DATA_PROGRESS => "0",
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

            $allowUpdate = (int)Config::get('progress.threshold', 0);
            $progCheck = $allowUpdate || false === $isPlayed;

            if ($progCheck && null !== ($progress = ag($item, 'viewOffset', null))) {
                // -- Plex reports play progress in milliseconds already no need to convert.
                $fields[iState::COLUMN_META_DATA][$context->backendName][iState::COLUMN_META_DATA_PROGRESS] = (string)$progress;
            }

            $entity = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $obj,
                opts: ['override' => $fields],
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
            if (true === ag($opts, Options::IS_GENERIC, false)) {
                return new Response(status: false, extra: ['http_code' => Status::OK->value]);
            }

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
