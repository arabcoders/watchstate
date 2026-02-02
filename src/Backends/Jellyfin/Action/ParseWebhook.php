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
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Options;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
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
    public const array WEBHOOK_ALLOWED_TYPES = [
        JFC::TYPE_MOVIE,
        JFC::TYPE_EPISODE,
    ];

    /**
     * @var array<string> Supported webhook events.
     */
    public const array WEBHOOK_ALLOWED_EVENTS = [
        'ItemAdded',
        'UserDataSaved',
        'PlaybackStart',
        'PlaybackStop',
    ];

    /**
     * @var array<string> Events that should be marked as tainted.
     */
    public const array WEBHOOK_TAINTED_EVENTS = [
        'PlaybackStart',
        'PlaybackStop',
        'ItemAdded',
    ];

    /**
     * @var array<string> Generic events that may not contain user id.
     */
    public const array WEBHOOK_GENERIC_EVENTS = ['ItemAdded'];

    public function __construct(
        private readonly iCache $cache,
    ) {}

    /**
     * Wrap the parser in try response block.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid GUID parser.
     * @param iRequest $request Request object.
     * @param array $opts Options to pass to the parser.
     *
     * @return Response The response.
     */
    public function __invoke(Context $context, iGuid $guid, iRequest $request, array $opts = []): Response
    {
        return $this->tryResponse(context: $context, fn: fn() => $this->parse($context, $guid, $request, $opts));
    }

    /**
     * Parse the Jellyfin webhook payload.
     *
     * @param Context $context Backend context.
     * @param iGuid $guid GUID parser.
     * @param iRequest $request Request object.
     * @param array $opts Options to pass to the parser.
     *
     * @return Response The response.
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
                    context: $logContext,
                ),
            ]);
        }

        $event = ag($json, 'NotificationType', 'unknown');
        $type = ag($json, 'ItemType', 'not_found');
        $id = ag($json, 'ItemId');

        if (null === $type || false === in_array($type, self::WEBHOOK_ALLOWED_TYPES, true)) {
            return new Response(status: false, extra: [
                'http_code' => Status::OK->value,
                'message' => r(
                    text: "{user}@{backend}: Webhook content type '{type}' is not supported.",
                    context: [...$logContext, 'type' => $type],
                ),
            ]);
        }

        if (null === $event || false === in_array($event, self::WEBHOOK_ALLOWED_EVENTS, true)) {
            return new Response(status: false, extra: [
                'http_code' => Status::OK->value,
                'message' => r(
                    text: "{user}@{backend}: Webhook event type '{event}' is not supported.",
                    context: [...$logContext, 'event' => $event],
                ),
            ]);
        }

        if (null === $id) {
            return new Response(status: false, extra: [
                'http_code' => Status::BAD_REQUEST->value,
                'message' => r('{user}@{backend}: No item id was found in body.', $logContext),
            ]);
        }

        try {
            $obj = $this->getItemDetails(context: $context, id: $id, opts: [
                ...$opts,
                Options::LOG_CONTEXT => ['request' => $json],
            ]);

            $isPlayed = (bool) ag($json, 'Played');
            $lastPlayedAt = true === $isPlayed ? make_date() : null;

            $logContext = [
                ...$logContext,
                'item' => [
                    'id' => ag($obj, 'Id'),
                    'type' => ag($obj, 'Type'),
                    'title' => match (ag($obj, 'Type')) {
                        JFC::TYPE_MOVIE => r('{title} ({year})', [
                            'title' => ag($obj, ['Name', 'OriginalTitle'], '??'),
                            'year' => ag($obj, 'ProductionYear', 0o000),
                        ]),
                        JFC::TYPE_EPISODE => r('{title} - ({season}x{episode})', [
                            'title' => ag($obj, 'SeriesName', '??'),
                            'season' => str_pad((string) ag($obj, 'ParentIndexNumber', 0), 2, '0', STR_PAD_LEFT),
                            'episode' => str_pad((string) ag($obj, 'IndexNumber', 0), 3, '0', STR_PAD_LEFT),
                        ]),
                        default => throw new InvalidArgumentException(
                            r('Unexpected Content type [{type}] was received.', ['type' => $type]),
                        ),
                    },
                    'year' => ag($obj, 'ProductionYear'),
                ],
            ];

            $providersId = [];

            foreach (array_change_key_case($json, CASE_LOWER) as $key => $val) {
                if (false === str_starts_with($key, 'provider_')) {
                    continue;
                }
                $providersId[after($key, 'provider_')] = $val;
            }

            $fields = [
                iState::COLUMN_WATCHED => (int) $isPlayed,
                iState::COLUMN_GUIDS => $guid->get(guids: $providersId, context: $logContext),
                iState::COLUMN_META_DATA => [
                    $context->backendName => [
                        iState::COLUMN_WATCHED => true === $isPlayed ? '1' : '0',
                        iState::COLUMN_GUIDS => $guid->parse(
                            guids: $providersId,
                            context: $logContext,
                        ),
                    ],
                ],
                iState::COLUMN_EXTRA => [
                    $context->backendName => [
                        iState::COLUMN_EXTRA_EVENT => $event,
                        iState::COLUMN_EXTRA_DATE => make_date('now'),
                    ],
                ],
            ];

            if (true === $isPlayed && null !== $lastPlayedAt) {
                $fields = array_replace_recursive($fields, [
                    iState::COLUMN_UPDATED => $lastPlayedAt->getTimestamp(),
                    iState::COLUMN_META_DATA => [
                        $context->backendName => [
                            iState::COLUMN_META_DATA_PLAYED_AT => (string) $lastPlayedAt,
                        ],
                    ],
                ]);
            }

            $allowUpdate = (int) Config::get('progress.threshold', 0);
            $progCheck = $allowUpdate || 0 === $isPlayed;

            if ($progCheck && null !== ($progress = ag($json, 'PlaybackPositionTicks', null))) {
                $fields[iState::COLUMN_META_DATA][$context->backendName][iState::COLUMN_META_DATA_PROGRESS] = (string) floor(
                    $progress / 1_00_00,
                ); // -- Convert to milliseconds.
            }

            $entityOpts = ['override' => $fields];

            if (true === (bool) ag($opts, Options::IS_GENERIC, false)) {
                $entityOpts[Options::IS_GENERIC] = true;
                $entityOpts[iCache::class] = $this->cache;
            }

            $entity = $this->createEntity(context: $context, guid: $guid, item: $obj, opts: $entityOpts)
                ->setIsTainted(isTainted: true === in_array($event, self::WEBHOOK_TAINTED_EVENTS, true));

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
                        level: Levels::ERROR,
                    ),
                    extra: [
                        'http_code' => Status::OK->value,
                        'message' => r('{user}@{backend}: No valid/supported external ids.', $logContext),
                    ],
                );
            }

            return new Response(status: true, response: $entity);
        } catch (Throwable $e) {
            if (true === (bool) ag($opts, Options::IS_GENERIC, false)) {
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
                    previous: $e,
                ),
                extra: [
                    'http_code' => Status::OK->value,
                    'message' => r('{user}@{backend}: Failed to process event check logs.', [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'user' => $context->userContext->name,
                    ]),
                ],
            );
        }
    }
}
