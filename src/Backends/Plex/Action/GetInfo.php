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

final class GetInfo
{
    use CommonTrait;

    protected string $action = 'plex.getInfo';

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
                $url = $context->backendUrl->withPath('/');

                $logContext = [
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'url' => (string)$url,
                ];

                $this->logger->debug(
                    message: "{action}: Requesting '{client}: {user}@{backend}' unique identifier.",
                    context: $logContext
                );

                $response = $this->http->request(
                    method: Method::GET,
                    url: (string)$url,
                    options: array_replace_recursive($context->getHttpOptions(), $opts['headers'] ?? [])
                );

                $content = $response->getContent(false);

                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: "{action}: Request for '{client}: {user}@{backend}' get info returned with unexpected '{status_code}' status code.",
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
                            message: "{action}: Request for '{client}: {user}@{backend}' get info returned with empty response.",
                            context: [...$logContext, 'response' => ['body' => $content],],
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
                        message: "{action}: Processing '{client}: {user}@{backend}' get info payload.",
                        context: [...$logContext, 'response' => ['body' => $item]],
                    );
                }

                $data = ag($item, 'MediaContainer', []);

                $ret = [
                    'type' => $context->clientName,
                    'name' => ag($data, 'friendlyName', null),
                    'version' => ag($data, 'version', null),
                    'identifier' => ag($data, 'machineIdentifier', null),
                    'platform' => ag($data, 'platform', null),
                ];

                if (true === ag_exists($opts, Options::RAW_RESPONSE)) {
                    $ret[Options::RAW_RESPONSE] = $data;
                }

                return new Response(
                    status: true,
                    response: $ret,
                );
            },
            action: $this->action
        );
    }
}
