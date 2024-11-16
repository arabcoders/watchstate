<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use DateInterval;
use JsonException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GetUsersList
{
    use CommonTrait;

    private int $maxRetry = 3;
    private string $action = 'plex.getUsersList';
    private iHttp $http;

    public function __construct(HttpClientInterface $http, protected LoggerInterface $logger)
    {
        $this->http = new RetryableHttpClient(client: $http, maxRetries: $this->maxRetry, logger: $this->logger);
    }

    /**
     * Get Users list.
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
            fn: fn() => $this->action($context, $opts),
            action: $this->action
        );
    }

    /**
     * Get Users list.
     *
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function action(Context $context, array $opts = []): Response
    {
        $cls = fn() => $this->real_request($context, $opts);

        return true === (bool)ag($opts, Options::NO_CACHE) ? $cls() : $this->tryCache(
            $context,
            'users_' . md5(json_encode($opts)),
            $cls,
            new DateInterval('PT5M'),
            $this->logger
        );
    }

    /**
     * Get Users list.
     *
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function real_request(Context $context, array $opts = []): Response
    {
        $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
            ->withPath('/api/v2/home/users/');

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            $url = $url->withQuery(http_build_query(['pin' => $pin]));
        }

        $tokenType = 'user';

        $response = $this->http->request('GET', (string)$url, [
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $context->backendToken,
                'X-Plex-Client-Identifier' => $context->backendId,
            ],
        ]);

        $this->logger->debug('Requesting [{backend}] Users list.', [
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        if (Status::OK !== Status::from($response->getStatusCode())) {
            $message = "Request for '{backend}' users list returned with unexpected '{status_code}' status code. Using {type} token.";

            if (null !== ag($context->options, Options::ADMIN_TOKEN)) {
                $adminResponse = $this->http->request('GET', (string)$url, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-Plex-Token' => ag($context->options, Options::ADMIN_TOKEN),
                        'X-Plex-Client-Identifier' => $context->backendId,
                    ],
                ]);

                if (Status::OK === Status::from($adminResponse->getStatusCode())) {
                    return $this->process($context, $url, $adminResponse, $opts);
                }
                $tokenType = 'user and admin';
            }

            return new Response(
                status: false,
                error: new Error(
                    message: $message,
                    context: [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                        'body' => $response->getContent(),
                        'type' => $tokenType,
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        return $this->process($context, $url, $response, $opts);
    }

    /**
     * Process the actual response.
     *
     * @param Context $context
     * @param UriInterface $url
     * @param ResponseInterface $response
     * @param array $opts
     *
     * @return Response Return processed response.
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function process(Context $context, UriInterface $url, ResponseInterface $response, array $opts): Response
    {
        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug('Parsing [{backend}] user list payload.', [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        $list = [];

        foreach (ag($json, 'users', []) as $user) {
            $data = [
                'id' => ag($user, 'id'),
                'uuid' => ag($user, 'uuid'),
                'name' => ag($user, ['friendlyName', 'username', 'title', 'email'], '??'),
                'admin' => (bool)ag($user, 'admin'),
                'guest' => (bool)ag($user, 'guest'),
                'restricted' => (bool)ag($user, 'restricted'),
                'updatedAt' => isset($user['updatedAt']) ? makeDate($user['updatedAt']) : 'Never',
            ];

            if (true === (bool)ag($opts, 'tokens')) {
                $tokenRequest = Container::getNew(GetUserToken::class)(
                    context: $context,
                    userId: ag($user, 'uuid'),
                    username: ag($data, 'name'),
                );

                if ($tokenRequest->hasError()) {
                    $this->logger->log(
                        $tokenRequest->error->level(),
                        $tokenRequest->error->message,
                        $tokenRequest->error->context
                    );
                }

                $data['token'] = $tokenRequest->isSuccessful() ? $tokenRequest->response : null;
            }

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $data['raw'] = $user;
            }

            $list[] = $data;
        }

        return new Response(status: true, response: $list);
    }
}
