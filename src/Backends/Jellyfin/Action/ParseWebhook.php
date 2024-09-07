<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinActionTrait;
use App\Backends\Jellyfin\JellyfinClient as JFC;
use App\Libs\Config;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Options;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Throwable;

/**
 * Class ParseWebhook
 *
 * This class is responsible for parsing a webhook payload from jellyfin backend.
 */
final class ParseWebhook
{
    use CommonTrait;
    use JellyfinActionTrait;

    /**
     * @var string Action name
     */
    protected string $action = 'jellyfin.parseWebhook';

    /**
     * @var array<string> Supported entity types.
     */
    protected const array WEBHOOK_ALLOWED_TYPES = [
        JFC::TYPE_MOVIE,
        JFC::TYPE_EPISODE,
    ];

    /**
     * @var array<string> Supported webhook events.
     */
    protected const array WEBHOOK_ALLOWED_EVENTS = [
        'ItemAdded',
        'UserDataSaved',
        'PlaybackStart',
        'PlaybackStop',
    ];

    /**
     * @var array<string> Events that should be marked as tainted.
     */
    protected const array WEBHOOK_TAINTED_EVENTS = [
        'PlaybackStart',
        'PlaybackStop',
        'ItemAdded',
    ];

    /**
     * Wrap the parser in try response block.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid GUID parser.
     * @param iRequest $request Request object.
     *
     * @return Response The response.
     */
    public function __invoke(Context $context, iGuid $guid, iRequest $request): Response
    {
        return $this->tryResponse(context: $context, fn: fn() => $this->parse($context, $guid, $request));
    }

    /**
     * Parse the Jellyfin webhook payload.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid GUID parser.
     * @param iRequest $request Request object.
     *
     * @return Response The response.
     */
    private function parse(Context $context, iGuid $guid, iRequest $request): Response
    {
        if (null === ($json = $request->getParsedBody())) {
            return new Response(status: false, extra: [
                'http_code' => 400,
                'message' => r('{client}: No payload.', ['client' => $context->clientName]),
            ]);
        }

        $event = ag($json, 'NotificationType', 'unknown');
        $type = ag($json, 'ItemType', 'not_found');
        $id = ag($json, 'ItemId');

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES)) {
            return new Response(status: false, extra: [
                'http_code' => 200,
                'message' => r('{backend}: Webhook content type [{type}] is not supported.', [
                    'backend' => $context->backendName,
                    'type' => $type,
                ])
            ]);
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS)) {
            return new Response(status: false, extra: [
                'http_code' => 200,
                'message' => r('{backend}: Webhook event type [{event}] is not supported.', [
                    'backend' => $context->backendName,
                    'event' => $event
                ])
            ]);
        }

        if (null === $id) {
            return new Response(status: false, extra: [
                'http_code' => 400,
                'message' => r('{backend}: No item id was found in body.', ['client' => $context->backendName]),
            ]);
        }

        try {
            $obj = $this->getItemDetails(context: $context, id: $id);

            $isPlayed = (bool)ag($json, 'Played');
            $lastPlayedAt = true === $isPlayed ? makeDate() : null;

            $logContext = [
                'item' => [
                    'id' => ag($obj, 'Id'),
                    'type' => ag($obj, 'Type'),
                    'title' => match (ag($obj, 'Type')) {
                        JFC::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($obj, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($obj, 'ProductionYear', 0000)
                        ]),
                        JFC::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($obj, 'SeriesName', '??'),
                            'season' => str_pad((string)ag($obj, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string)ag($obj, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        ]),
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

            $providersId = [];

            foreach (array_change_key_case($json, CASE_LOWER) as $key => $val) {
                if (false === str_starts_with($key, 'provider_')) {
                    continue;
                }
                $providersId[after($key, 'provider_')] = $val;
            }

            if (JFC::TYPE_EPISODE === $type && true === $disableGuid) {
                $guids = [];
            } else {
                $guids = $guid->get(guids: $providersId, context: $logContext);
            }

            $fields = [
                iState::COLUMN_WATCHED => (int)$isPlayed,
                iState::COLUMN_GUIDS => $guids,
                iState::COLUMN_META_DATA => [
                    $context->backendName => [
                        iState::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                        iState::COLUMN_GUIDS => $guid->parse(
                            guids: $providersId,
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
                    iState::COLUMN_UPDATED => $lastPlayedAt->getTimestamp(),
                    iState::COLUMN_META_DATA => [
                        $context->backendName => [
                            iState::COLUMN_META_DATA_PLAYED_AT => (string)$lastPlayedAt,
                        ]
                    ],
                ]);
            }

            $entity = $this->createEntity(
                context: $context,
                guid: $guid,
                item: $obj,
                opts: ['override' => $fields, Options::DISABLE_GUID => $disableGuid],
            )->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS));

            if (false === $isPlayed && null !== ($progress = ag($json, 'PlaybackPositionTicks', null))) {
                $fields[iState::COLUMN_META_DATA][$context->backendName][iState::COLUMN_META_DATA_PROGRESS] = (string)floor(
                    $progress / 10_000
                ); // -- Convert to milliseconds.
            }

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
                    'http_code' => 200,
                    'message' => $context->backendName . ': Failed to handle payload. Check logs.'
                ],
            );
        }
    }
}
