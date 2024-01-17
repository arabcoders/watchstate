<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Options;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GetSessions
{
    use CommonTrait;

    private string $action = 'plex.getSessions';

    public function __construct(
        protected HttpClientInterface $http,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Get Backend unique identifier.
     *
     * @param Context $context
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $opts) {
                $url = $context->backendUrl->withPath('/status/sessions');

                $this->logger->debug('Requesting [{client}: {backend}] play sessions.', [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'url' => $url
                ]);

                $response = $this->http->request(
                    'GET',
                    (string)$url,
                    array_replace_recursive($context->backendHeaders, $opts['headers'] ?? [])
                );

                $content = $response->getContent(false);

                if (200 !== $response->getStatusCode()) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: 'Request for [{backend}] {action} returned with unexpected [{status_code}] status code.',
                            context: [
                                'action' => $this->action,
                                'client' => $context->clientName,
                                'backend' => $context->backendName,
                                'status_code' => $response->getStatusCode(),
                                'url' => (string)$url,
                                'response' => $content,
                            ],
                            level: Levels::WARNING
                        )
                    );
                }

                if (empty($content)) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: 'Request for [{backend}] {action} returned with empty response.',
                            context: [
                                'action' => $this->action,
                                'client' => $context->clientName,
                                'backend' => $context->backendName,
                                'url' => (string)$url,
                                'response' => $content,
                            ],
                            level: Levels::ERROR
                        )
                    );
                }

                $item = json_decode(
                    json: $content,
                    associative: true,
                    flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
                );

                if (true === $context->trace) {
                    $this->logger->debug('Processing [{client}: {backend}] {action} payload.', [
                        'action' => $this->action,
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'trace' => $item,
                    ]);
                }

                $data = ag($item, 'MediaContainer.Metadata', []);

                $ret = [
                    'sessions' => [],
                ];

                if (true === ag_exists($opts, Options::RAW_RESPONSE)) {
                    $ret[Options::RAW_RESPONSE] = $item;
                }

                foreach ($data as $session) {
                    $uuid = preg_match(
                        '#/users/(.+?)/avatar#i',
                        ag($session, 'User.thumb'),
                        $matches
                    ) ? $matches[1] : null;

                    $ret['sessions'][] = [
                        'user_uid' => (int)ag($session, 'User.id'),
                        'user_uuid' => $uuid,
                        'item_id' => (int)ag($session, 'ratingKey'),
                        'item_title' => ag($session, 'title'),
                        'item_type' => ag($session, 'type'),
                        'item_offset_at' => ag($session, 'viewOffset'),
                        'session_state' => ag($session, 'Player.state'),
                        'session_id' => ag($session, 'Session.id', ag($session, 'sessionKey')),
                    ];
                }

                return new Response(status: true, response: $ret);
            },
            action: $this->action
        );
    }
}
