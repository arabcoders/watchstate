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
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

/**
 * Class GetInfo
 *
 * This class retrieves information from a jellyfin backend.
 */
class GetInfo
{
    use CommonTrait;

    protected string $action = 'jellyfin.getInfo';

    /**
     * Class constructor.
     *
     * @param iHttp $http The HTTP client instance to use.
     * @param iLogger $logger The logger instance to use.
     */
    public function __construct(protected readonly iHttp $http, protected readonly iLogger $logger)
    {
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
                $url = $context->backendUrl->withPath('/system/Info');
                $logContext = [
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'url' => (string)$url
                ];

                $this->logger->debug("{action}: Requesting '{client}: {user}@{backend}' info.", $logContext);

                $response = $this->http->request(
                    method: Method::GET,
                    url: (string)$url,
                    options: array_replace_recursive(
                        $context->getHttpOptions(),
                        true === ag_exists($opts, 'headers') ? ['headers' => $opts['headers']] : [],
                    )
                );

                $content = $response->getContent(false);

                if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: "{action}: '{client}: {user}@{backend}' request returned with unexpected '{status_code}' status code.",
                            context: [
                                ...$logContext,
                                'status_code' => $response->getStatusCode(),
                                'response' => [
                                    'body' => $content,
                                ],
                            ],
                            level: Levels::WARNING
                        )
                    );
                }

                if (empty($content)) {
                    return new Response(
                        status: false,
                        error: new Error(
                            message: "{action}: '{client}: {user}@{backend}' request returned with empty response. Please make sure the container can communicate with the backend.",
                            context: [
                                ...$logContext,
                                'response' => [
                                    'status_code' => $response->getStatusCode(),
                                    'body' => $content
                                ],
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
                    $this->logger->debug("{action}: Processing '{client}: {user}@{backend}' request payload.", [
                        ...$logContext,
                        'trace' => $item,
                    ]);
                }

                $ret = [
                    'type' => $context->clientName,
                    'name' => ag($item, 'ServerName'),
                    'version' => ag($item, 'Version'),
                    'identifier' => ag($item, 'Id'),
                    'platform' => ag($item, 'OperatingSystem'),
                ];

                if (true === ag_exists($opts, Options::RAW_RESPONSE)) {
                    $ret[Options::RAW_RESPONSE] = $item;
                }

                return new Response(status: true, response: $ret);
            },
            action: $this->action,
        );
    }
}
