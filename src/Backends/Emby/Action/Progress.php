<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\GuidInterface as iGuid;
use App\Backends\Common\Response;
use App\Backends\Emby\EmbyActionTrait;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use App\Libs\QueueRequests;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class Progress
{
    use CommonTrait;
    use EmbyActionTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Push play progress.
     *
     * @param Context $context
     * @param iGuid $guid
     * @param array<iState> $entities
     * @param QueueRequests $queue
     * @param DateTimeInterface|null $after
     * @return Response
     */
    public function __invoke(
        Context $context,
        iGuid $guid,
        array $entities,
        QueueRequests $queue,
        DateTimeInterface|null $after = null
    ): Response {
        return $this->tryResponse(context: $context, fn: fn() => $this->action(
            $context,
            $guid,
            $entities,
            $queue,
            $after
        ), action: 'emby.progress');
    }

    private function action(
        Context $context,
        iGuid $guid,
        array $entities,
        QueueRequests $queue,
        DateTimeInterface|null $after = null
    ): Response {
        $ignoreDate = (bool)ag($context->options, Options::IGNORE_DATE, false);

        foreach ($entities as $key => $entity) {
            if (true !== ($entity instanceof iState)) {
                continue;
            }

            if (null !== $after && false === (bool)ag($context->options, Options::IGNORE_DATE, false)) {
                if ($after->getTimestamp() > $entity->updated) {
                    continue;
                }
            }

            $metadata = $entity->getMetadata($context->backendName);

            $logContext = [
                'item' => [
                    'id' => $entity->id,
                    'type' => $entity->type,
                    'title' => $entity->getName(),
                ],
            ];

            if ($context->backendName === $entity->via) {
                $this->logger->info('Ignoring [{item.title}] for [{client}: {backend}]. Event generator.', [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            if (null === ag($metadata, iState::COLUMN_ID, null)) {
                $this->logger->warning('Ignoring [{item.title}] for [{client}: {backend}]. No metadata.', [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }

            $senderDate = ag($entity->getExtra($entity->via), iState::COLUMN_EXTRA_DATE);
            if (null === $senderDate) {
                $this->logger->warning('Ignoring [{item.title}] for [{client}: {backend}]. Sender date is not set.', [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);
                continue;
            }
            $senderDate = makeDate($senderDate)->getTimestamp();

            $datetime = ag($entity->getExtra($context->backendName), iState::COLUMN_EXTRA_DATE, null);
            if (false === $ignoreDate && null !== $datetime && makeDate($datetime)->getTimestamp() > $senderDate) {
                $this->logger->warning(
                    'Ignoring [{item.title}] for [{client}: {backend}]. Sender date is older than recorded backend date.',
                    [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        ...$logContext,
                    ]
                );
                continue;
            }

            $logContext['remote']['id'] = ag($metadata, iState::COLUMN_ID);

            try {
                $remoteItem = $this->createEntity(
                    $context,
                    $guid,
                    $this->getItemDetails($context, $logContext['remote']['id'], [
                        Options::NO_CACHE => true,
                    ])
                );

                if (false === $ignoreDate && makeDate($remoteItem->updated)->getTimestamp() > $senderDate) {
                    $this->logger->info(
                        'Ignoring [{item.title}] for [{client}: {backend}]. Sender date is older than backend remote item date.',
                        [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );
                    continue;
                }

                if ($remoteItem->isWatched()) {
                    $this->logger->info(
                        'Ignoring [{item.title}] for [{client}: {backend}]. The backend reported the item as watched.',
                        [
                            'client' => $context->clientName,
                            'backend' => $context->backendName,
                            ...$logContext,
                        ]
                    );
                    continue;
                }
            } catch (\RuntimeException $e) {
                $this->logger->error(
                    message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] get {item.type} [{item.title}] status. Error [{error.message} @ {error.file}:{error.line}].',
                    contexT: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        ...$logContext,
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
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
                continue;
            }

            try {
                $url = $context->backendUrl->withPath(
                    r('/Users/{user_id}/Items/{item_id}/UserData', [
                        'user_id' => $context->backendUser,
                        'item_id' => $logContext['remote']['id'],
                    ])
                );

                $logContext['remote']['url'] = (string)$url;

                $this->logger->debug('Updating [{client}: {backend}] {item.type} [{item.title}] watch progress.', [
                    // -- convert time to ticks for emby to understand it.
                    'time' => floor($entity->getPlayProgress() * 1_00_00),
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    ...$logContext,
                ]);

                if (false === (bool)ag($context->options, Options::DRY_RUN, false)) {
                    $queue->add(
                        $this->http->request(
                            'POST',
                            (string)$url,
                            array_replace_recursive($context->backendHeaders, [
                                'headers' => [
                                    'Content-Type' => 'application/json',
                                ],
                                'json' => [
                                    'PlaybackPositionTicks' => (string)floor($entity->getPlayProgress() * 1_00_00),
                                ],
                                'user_data' => [
                                    'id' => $key,
                                    'context' => $logContext + [
                                            'backend' => $context->backendName,
                                        ],
                                ],
                            ])
                        )
                    );
                }
            } catch (Throwable $e) {
                $this->logger->error(
                    message: 'Exception [{error.kind}] was thrown unhandled during [{client}: {backend}] change {item.type} [{item.title}] watch progress. Error [{error.message} @ {error.file}:{error.line}].',
                    context: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
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
                            'trace' => $context->trace ? $e->getTrace() : [],
                        ],
                    ]
                );
            }
        }

        return new Response(status: true, response: $queue);
    }
}
