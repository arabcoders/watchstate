<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Psr\SimpleCache\CacheInterface as iCache;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

/**
 * Class GetInfo
 *
 * This class retrieves information from a jellyfin backend.
 */
class GetSessions
{
    use CommonTrait;

    protected string $action = 'jellyfin.getSessions';

    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
        protected readonly iCache $cache
    ) {
    }

    /**
     * Get backend information.
     *
     * @param Context $context Backend context.
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $opts) {
                $url = $context->backendUrl->withPath('/Sessions');

                $logContext = [
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'url' => (string)$url
                ];

                $this->logger->debug("{action}: Requesting '{client}: {user}@{backend}' play sessions.", $logContext);

                $response = $this->http->request(
                    method: Method::GET,
                    url: (string)$url,
                    options: array_replace_recursive($context->backendHeaders, $opts['headers'] ?? [])
                );

                $content = $response->getContent(false);

                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: "{action}: Request for '{client}: {user}@{backend}' get sessions returned with unexpected '{status_code}' status code.",
                            context: [
                                ...$logContext,
                                'status_code' => $response->getStatusCode(),
                                'response' => ['body' => $content],
                            ],
                            level: Levels::WARNING
                        )
                    );
                }

                if (empty($content)) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: "{action}: Request for '{client}: {user}@{backend}' get sessions returned with empty response.",
                            context: [
                                ...$logContext,
                                'response' => ['status_code' => $response->getStatusCode(), 'body' => $content],
                            ],
                            level: Levels::ERROR
                        )
                    );
                }

                $items = json_decode(
                    json: $content,
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                if (true === $context->trace) {
                    $this->logger->debug("Processing '{client}: {user}@{backend}' {action} payload.", [
                        ...$logContext,
                        'response' => ['body' => $items],
                    ]);
                }

                $filtered = [];

                foreach ($items as $item) {
                    if (null === ag($item, 'NowPlayingItem')) {
                        continue;
                    }

                    $filtered[] = $item;
                }

                $ret = [
                    'sessions' => [],
                ];

                foreach ($filtered as $item) {
                    $ret['sessions'][] = [
                        'user_id' => ag($item, 'UserId'),
                        'user_name' => ag($item, 'UserName'),
                        'item_id' => ag($item, 'NowPlayingItem.Id'),
                        'item_title' => ag($item, 'NowPlayingItem.Name'),
                        'item_type' => ag($item, 'NowPlayingItem.Type'),
                        'item_offset_at' => ag($item, 'PlayState.PositionTicks') / 1_00_00,
                        'session_state' => (bool)ag($item, 'PlayState.IsPaused', false) === true ? 'paused' : 'playing',
                        'session_updated_at' => makeDate(ag($item, 'LastActivityDate')),
                        'session_id' => ag($item, 'Id'),
                    ];
                }

                if (true === ag_exists($opts, Options::RAW_RESPONSE)) {
                    $ret[Options::RAW_RESPONSE] = $items;
                }

                return new Response(status: true, response: $ret);
            },
            action: $this->action,
        );
    }
}
