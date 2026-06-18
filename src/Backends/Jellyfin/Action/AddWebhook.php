<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class AddWebhook
{
    use CommonTrait;

    private string $action = 'jellyfin.addWebhook';

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
     * @param string $webhookUrl
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
            $logContext = [
                'action' => $this->action,
                'identity' => [
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                ],
            ];

            $pluginsUrl = $context->backendUrl->withPath('/Plugins');

            $this->logger->debug("Getting installed plugins for '{identity.user}@{identity.backend}'.", [
                ...$logContext,
                'request' => ['url' => (string) $pluginsUrl],
            ]);

            $response = $this->http->request(Method::GET, $pluginsUrl, array_replace_recursive(
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
                        message: "Request for '{identity.user}@{identity.backend}' plugins list returned with unexpected '{response.status_code}' status code.",
                        context: [
                            ...$logContext,
                            'response' => [
                                'status_code' => $response->getStatusCode(),
                                'body' => $content,
                                'reason' => $reason,
                            ],
                        ],
                        level: Levels::WARNING,
                        extra: ['error' => $reason],
                    ),
                );
            }

            if (empty($content)) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: "Request for '{identity.user}@{identity.backend}' plugins list returned with empty response.",
                        context: [...$logContext, 'response' => ['body' => $content]],
                        level: Levels::ERROR,
                    ),
                );
            }

            $plugins = json_decode(
                json: $content,
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
            );

            $pluginId = null;
            foreach ($plugins ?? [] as $plugin) {
                $pluginName = strtolower(ag($plugin, 'Name', ''));
                if ('webhook' !== $pluginName) {
                    continue;
                }
                $pluginId = ag($plugin, 'Id');
                break;
            }

            if (null === $pluginId) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: "Webhook plugin not found on '{identity.user}@{identity.backend}'.",
                        context: $logContext,
                        level: Levels::ERROR,
                    ),
                );
            }

            $url = $context->backendUrl->withPath('/Plugins/' . $pluginId . '/Configuration');

            $logContext['url'] = (string) $url;

            $this->logger->debug("Getting current webhooks for '{identity.user}@{identity.backend}'.", $logContext);

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
                        message: "Request for '{identity.user}@{identity.backend}' get webhooks returned with unexpected '{response.status_code}' status code.",
                        context: [
                            ...$logContext,
                            'response' => [
                                'status_code' => $response->getStatusCode(),
                                'body' => $content,
                                'reason' => $reason,
                            ],
                        ],
                        level: Levels::WARNING,
                        extra: ['error' => $reason],
                    ),
                );
            }

            if (empty($content)) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: "Request for '{identity.user}@{identity.backend}' get webhooks returned with empty response.",
                        context: [...$logContext, 'response' => ['body' => $content]],
                        level: Levels::ERROR,
                    ),
                );
            }

            $config = json_decode(
                json: $content,
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
            );

            if (true === $context->trace) {
                $this->logger->debug(
                    message: "Processing '{identity.user}@{identity.backend}' get info payload.",
                    context: [...$logContext, 'response' => ['body' => $config]],
                );
            }

            $genericOptions = ag($config, 'GenericOptions', []);

            $userIds = [];

            $usersList = Container::get(GetUsersList::class)($context);
            if (false === $usersList->isSuccessful()) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: "Failed to get users list for '{identity.user}@{identity.backend}' while adding webhook.",
                        context: [
                            ...$logContext,
                            'response' => $usersList->error?->context['response'] ?? null,
                        ],
                        level: Levels::WARNING,
                    ),
                );
            }

            foreach ($usersList->response as $user) {
                if (null === ($id = ag($user, 'id'))) {
                    continue;
                }
                $userIds[] = $id;
            }

            $webhookHost = parse_url($webhookUrl, PHP_URL_HOST);
            $webhookPath = parse_url($webhookUrl, PHP_URL_PATH);
            $foundIndex = null;

            foreach ($genericOptions as $i => $webhook) {
                if (null === ($rUrl = ag($webhook, 'WebhookUri'))) {
                    continue;
                }

                if (true === str_contains($rUrl, $webhookHost) && true === str_contains($rUrl, $webhookPath)) {
                    $foundIndex = $i;
                    break;
                }
            }

            $entry = [
                'Headers' => [['Key' => 'Content-Type', 'Value' => 'application/json']],
                'Fields' => [],
                'NotificationTypes' => ['ItemAdded', 'PlaybackStart', 'PlaybackStop', 'UserDataSaved'],
                'WebhookName' => 'WatchState Webhook',
                'WebhookUri' => $webhookUrl,
                'EnableMovies' => true,
                'EnableEpisodes' => true,
                'EnableSeries' => false,
                'EnableSeasons' => false,
                'EnableAlbums' => false,
                'EnableSongs' => false,
                'EnableVideos' => false,
                'SendAllProperties' => true,
                'TrimWhitespace' => true,
                'SkipEmptyMessageBody' => true,
                'EnableWebhook' => true,
                'Template' => '',
                'UserFilter' => $userIds,
            ];

            $mode = null === $foundIndex ? 'add' : 'update';
            if (null !== $foundIndex) {
                $genericOptions[$foundIndex] = $entry;
            } else {
                $genericOptions[] = $entry;
            }

            $config['GenericOptions'] = $genericOptions;

            $resp = $this->http->request(
                method: Method::POST,
                url: (string) $url,
                options: array_replace_recursive($context->getHttpOptions(), $opts, [
                    'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                    'json' => $config,
                ]),
            );

            if (Status::NO_CONTENT !== Status::tryFrom($resp->getStatusCode())) {
                $reason = $this->getBackendResponseReason($resp->getContent(false));
                return new Response(
                    status: false,
                    error: new Error(
                        message: "Request for '{identity.user}@{identity.backend}' to {mode} webhook returned with unexpected '{response.status_code}' status code.",
                        context: [
                            ...$logContext,
                            'mode' => $mode,
                            'response' => [
                                'status_code' => $resp->getStatusCode(),
                                'body' => $resp->getContent(false),
                                'reason' => $reason,
                            ],
                        ],
                        level: Levels::WARNING,
                        extra: ['error' => $reason],
                    ),
                );
            }

            return new Response(status: true, response: []);
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Failed during '{identity.user}@{identity.backend}' request to {mode} webhook. {exception.message}",
                    context: [
                        'identity' => [
                            'user' => $context->userContext->name,
                            'backend' => $context->backendName,
                            'client' => $context->clientName,
                        ],
                        'mode' => $mode ?? 'add',
                        ...exception_log($e),
                    ],
                    level: Levels::ERROR,
                    previous: $e,
                ),
            );
        }
    }
}
