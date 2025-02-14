<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GetSessions
{
    use CommonTrait;

    private string $action = 'plex.getSessions';

    public function __construct(protected readonly iHttp $http, protected readonly iLogger $logger)
    {
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

                $logContext = [
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'url' => (string)$url,
                ];

                $this->logger->debug(
                    message: "{action}: Requesting '{client}: {user}@{backend}' active play sessions.",
                    context: $logContext
                );

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
                            message: "{action}: Request for '{client}: {user}@{backend}' active play sessions returned with unexpected '{status_code}' status code.",
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
                            message: "{action}: Request for '{client}: {user}@{backend}' active play sessions returned with empty response.",
                            context: [
                                ...$logContext,
                                'status_code' => $response->getStatusCode(),
                                'response' => ['body' => $content],
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
                    $this->logger->debug(
                        message: "{action}: Processing '{client}: {user}@{backend}' active play sessions payload.",
                        context: [...$logContext, 'response' => ['body' => $content]]
                    );
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
                        pattern: '#/users/(.+?)/avatar#i',
                        subject: ag($session, 'User.thumb'),
                        matches: $matches
                    ) ? $matches[1] : null;

                    $item = [
                        'user_id' => (int)ag($session, 'User.id'),
                        'user_name' => ag($session, 'User.title'),
                        'user_uuid' => $uuid,
                        'item_id' => (int)ag($session, 'ratingKey'),
                        'item_title' => ag($session, 'title'),
                        'item_type' => ag($session, 'type'),
                        'item_offset_at' => ag($session, 'viewOffset'),
                        'session_state' => ag($session, 'Player.state'),
                        'session_id' => ag($session, 'Session.id', ag($session, 'sessionKey')),
                    ];

                    if ('playing' === $item['session_state']) {
                        $item['session_updated_at'] = makeDate(date: time());
                    }

                    $ret['sessions'][] = $item;
                }

                return new Response(status: true, response: $ret);
            },
            action: $this->action
        );
    }
}
