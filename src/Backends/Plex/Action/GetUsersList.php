<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Options;
use DateInterval;
use JsonException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GetUsersList
{
    private int $maxRetry = 3;
    private string $action = 'plex.getUsersList';

    use CommonTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
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

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: 'Request for [{backend}] users list returned with unexpected [{status_code}] status code.',
                    context: [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                        'body' => $response->getContent(),
                    ],
                    level: Levels::ERROR
                ),
            );
        }

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
