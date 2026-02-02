<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Libs\APIResponse;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Stream;
use Psr\Http\Message\StreamInterface as iStream;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class Proxy
{
    use CommonTrait;

    private string $action = 'jellyfin.proxy';

    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
    ) {}

    /**
     * Get Backend unique identifier.
     *
     * @param Context $context backend context.
     * @param Method $method request method.
     * @param iUri $uri request uri.
     * @param array|iStream|null $body request body.
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(
        Context $context,
        Method $method,
        iUri $uri,
        array|iStream|null $body = null,
        array $opts = [],
    ): Response {
        return $this->tryResponse(
            context: $context,
            fn: function () use ($context, $method, $uri, $body, $opts) {
                $url = (string) $uri;

                $logContext = [
                    'action' => $this->action,
                    'client' => $context->clientName,
                    'backend' => $context->backendName,
                    'user' => $context->userContext->name,
                    'url' => $url,
                ];

                $this->logger->debug(
                    message: "{action}: proxying request via '{client}: {user}@{backend}'.",
                    context: $logContext,
                );

                $requestOpts = $context->getHttpOptions();

                if (isset($opts['headers'])) {
                    $requestOpts['headers'] = array_replace_recursive($requestOpts['headers'], $opts['headers']);
                }

                if (null !== $body && false === in_array($method, [Method::GET, Method::HEAD], true)) {
                    if (true === $body instanceof iStream) {
                        $requestOpts['body'] = $body->detach();
                    }
                    if (true === is_array($body) && count($body) > 0) {
                        $requestOpts['json'] = $body;
                    }
                }

                $response = $this->http->request(method: $method, url: $url, options: $requestOpts);

                return new Response(
                    status: true,
                    response: new APIResponse(
                        status: Status::from($response->getStatusCode()),
                        headers: $response->getHeaders(false),
                        stream: Stream::create($response->getContent(false)),
                    ),
                );
            },
            action: $this->action,
        );
    }
}
