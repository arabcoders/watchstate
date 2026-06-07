<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class AddWebhook
{
    use CommonTrait;

    private string $action = 'emby.addWebhook';

    /**
     * Undocumented function
     *
     * @param iHttp&\App\Libs\Extends\HttpClient $http
     * @param iLogger $logger
     */
    public function __construct(
        private readonly iHttp $http,
        private readonly iLogger $logger,
    ) {}

    /**
     * Add a webhook.
     *
     * @param Context $context
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string $webhookUrl, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->addWebhook($context, $webhookUrl, $opts),
            action: $this->action,
        );
    }

    /**
     * @param Context $context
     * @param string $webhookUrl
     * @param array $opts optional options.
     *
     * @return Response
     */
    private function addWebhook(Context $context, string $webhookUrl, array $opts = []): Response
    {
        try {
            $url = $context
                ->backendUrl
                ->withPath('/emby/Notifications/Services/Configured')
                ->withQuery(http_build_query([
                    'UserId' => $context->backendUser,
                ]));

            $logContext = [
                'action' => $this->action,
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'url' => (string) $url,
            ];

            $this->logger->debug("Getting current webhooks for '{user}@{backend}'.", $logContext);

            $response = $this->http->request(Method::GET, $url, array_replace_recursive(
                $context->getHttpOptions(),
                ['headers' => ['Accept' => 'application/json']],
                $opts,
            ));

            $content = $response->getContent(false);

            if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                $reason = $this->getBackendResponseReason($content);
                return new Response(
                    status: false,
                    error: new Error(
                        message: "{action}: Request for '{client}: {user}@{backend}' get webhooks returned with unexpected '{status_code}' status code.",
                        context: [
                            ...$logContext,
                            'status_code' => $response->getStatusCode(),
                            'response' => [
                                'body' => $content,
                                'reason' => $reason,
                            ],
                        ],
                        level: Levels::ERROR,
                        extra: ['error' => $reason],
                    ),
                );
            }

            if (empty($content)) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: "{action}: Request for '{client}: {user}@{backend}' get webhooks returned with empty response.",
                        context: [...$logContext, 'response' => ['body' => $content]],
                        level: Levels::ERROR,
                    ),
                );
            }

            $item = json_decode(
                json: $content,
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
            );

            if (true === $context->trace) {
                $this->logger->debug(
                    message: "{action}: Processing '{client}: {user}@{backend}' get info payload.",
                    context: [...$logContext, 'response' => ['body' => $item]],
                );
            }

            $json = json_decode(
                json: $response->getContent(),
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
            );

            $webhookHost = parse_url($webhookUrl, PHP_URL_HOST);
            $webhookPath = parse_url($webhookUrl, PHP_URL_PATH);
            $found = null;
            foreach ($json ?? [] as $webhook) {
                if (null === ($rUrl = ag($webhook, 'Options.Url'))) {
                    continue;
                }

                if (true === str_contains($rUrl, $webhookHost) && true === str_contains($rUrl, $webhookPath)) {
                    $found = $webhook;
                    break;
                }
            }

            $body = [
                'NotifierKey' => 'webhooknotifications',
                'SetupModuleUrl' => 'configurationpage?name=webhookeditorjs',
                'ServiceName' => 'Webhooks',
                'PluginId' => '85a7b1d4-fbda-4e85-a0a2-ac303c9946a4',
                'Enabled' => true,
                'UserIds' => [],
                'DeviceIds' => [],
                'LibraryIds' => [],
                'EventIds' => [
                    'library.new',
                    'playback.start',
                    'playback.pause',
                    'playback.unpause',
                    'playback.stop',
                    'item.markplayed',
                    'item.markunplayed',
                    'external.externalnotification',
                ],
                'IsSelfNotification' => false,
                'GroupItems' => false,
                'Options' => ['EnableMultipartFormData' => false, 'Url' => $webhookUrl],
                'FriendlyName' => 'WatchState Webhook',
            ];

            $mode = null === $found ? 'add' : 'update';
            if (null !== $found) {
                $body = array_replace_recursive($found, $body);
            }

            $resp = $this->http->request(
                method: Method::POST,
                url: null === $found ? $url : (string) $context->backendUrl->withPath('/emby/Notifications/Services/Configured'),
                options: array_replace_recursive($context->getHttpOptions(), $opts, [
                    'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                    'json' => $body,
                ]),
            );

            if (Status::NO_CONTENT !== Status::tryFrom($resp->getStatusCode())) {
                $reason = $this->getBackendResponseReason($resp->getContent(false));
                return new Response(
                    status: false,
                    error: new Error(
                        message: "{action}: Request for '{client}: {user}@{backend}' to {mode} webhook returned with unexpected '{status_code}' status code.",
                        context: [
                            ...$logContext,
                            'mode' => $mode,
                            'status_code' => $resp->getStatusCode(),
                            'response' => [
                                'body' => $resp->getContent(false),
                                'reason' => $reason,
                            ],
                        ],
                        level: Levels::ERROR,
                        extra: ['error' => $reason],
                    ),
                );
            }

            return new Response(status: true, response: []);
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' request to {mode} webhook. Error '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'user' => $context->userContext->name,
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'mode' => $mode ?? 'add',
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'exception' => [
                            'file' => after($e->getFile(), ROOT_PATH),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ],
                    level: Levels::ERROR,
                    previous: $e,
                ),
            );
        }
    }
}
