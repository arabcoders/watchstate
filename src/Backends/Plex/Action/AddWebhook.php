<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

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

    private string $action = 'plex.addWebhook';

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
            $url = Container::getNew(iUri::class)
                ->withPort(443)
                ->withScheme('https')
                ->withHost('clients.plex.tv')
                ->withPath('/api/v2/user/webhooks');

            $logContext = [
                'action' => $this->action,
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
                'url' => (string) $url,
            ];

            if (null !== ($pin = ag($opts, Options::PLEX_USER_PIN, ag($context->options, Options::PLEX_USER_PIN)))) {
                $url = $url->withQuery(http_build_query(['pin' => (string) $pin]));
            }

            $this->logger->debug("Getting current webhooks for '{user}@{backend}'.", $logContext);

            $response = $this->http->request(Method::GET, (string) $url, array_replace_recursive(
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
                        level: Levels::WARNING,
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

            $json = json_decode(
                json: $content,
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
            );

            if (true === $context->trace) {
                $this->logger->debug(
                    message: "{action}: Processing '{client}: {user}@{backend}' get info payload.",
                    context: [...$logContext, 'response' => ['body' => $content, 'json' => $json]],
                );
            }

            $existingUrls = [];
            $webhookHost = parse_url($webhookUrl, PHP_URL_HOST);
            $webhookPath = parse_url($webhookUrl, PHP_URL_PATH);
            foreach ($json ?? [] as $webhook) {
                if (null === ($rUrl = ag($webhook, 'url'))) {
                    continue;
                }

                if (true === str_contains($rUrl, $webhookHost) && true === str_contains($rUrl, $webhookPath)) {
                    return new Response(status: true, response: []);
                }

                $existingUrls[] = $rUrl;
            }

            $existingUrls[] = $webhookUrl;
            $resp = $this->http->request(
                method: Method::POST,
                url: (string) $url,
                options: array_replace_recursive(
                    $context->getHttpOptions(),
                    [
                        'headers' => [
                            'Accept' => 'application/json',
                            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                        ],
                        'body' => implode('&', array_map(
                            static fn(string $url) => 'urls%5B%5D=' . urlencode($url),
                            $existingUrls,
                        )),
                    ],
                    $opts,
                ),
            );

            if (false === in_array(Status::tryFrom($resp->getStatusCode()), [Status::OK, Status::CREATED], true)) {
                $reason = $this->getBackendResponseReason($resp->getContent(false));
                return new Response(
                    status: false,
                    error: new Error(
                        message: "{action}: Request for '{client}: {user}@{backend}' to add webhook returned with unexpected '{status_code}' status code.",
                        context: [
                            ...$logContext,
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
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' request to add webhook. Error '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'user' => $context->userContext->name,
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
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
