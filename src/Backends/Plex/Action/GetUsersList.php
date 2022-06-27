<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Common\Context;
use App\Libs\Container;
use App\Libs\Options;
use JsonException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class GetUsersList
{
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
        return $this->tryResponse(context: $context, fn: fn() => $this->getUsers($context, $opts));
    }

    /**
     * Get Users list.
     *
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function getUsers(Context $context, array $opts = []): Response
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

        $this->logger->debug('Requesting [%(backend)] Users list.', [
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error:  new Error(
                            message: 'Request for [%(backend)] users list returned with unexpected [%(status_code)] status code.',
                            context: [
                                         'backend' => $context->backendName,
                                         'status_code' => $response->getStatusCode(),
                                     ],
                            level:   Levels::ERROR
                        ),
            );
        }

        $json = json_decode(
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug('Parsing [%(backend)] user list payload.', [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        $list = [];

        $adminsCount = 0;

        $users = ag($json, 'users', []);

        foreach ($users as $user) {
            if (true === (bool)ag($user, 'admin')) {
                $adminsCount++;
            }
        }

        foreach ($users as $user) {
            $data = [
                'id' => ag($user, 'admin') && $adminsCount <= 1 ? 1 : ag($user, 'id'),
                'name' => ag($user, ['friendlyName', 'username', 'title', 'email'], '??'),
                'admin' => (bool)ag($user, 'admin'),
                'guest' => (bool)ag($user, 'guest'),
                'restricted' => (bool)ag($user, 'restricted'),
                'updatedAt' => isset($user['updatedAt']) ? makeDate($user['updatedAt']) : 'Never',
            ];

            if (true === (bool)ag($opts, 'tokens')) {
                $tokenRequest = $this->getUserToken(
                    context:  $context,
                    userId:   ag($user, 'uuid'),
                    username: ag($data, 'name'),
                );

                if ($tokenRequest->hasError()) {
                    $this->logger->log(
                        $tokenRequest->error->level(),
                        $tokenRequest->error->message,
                        $tokenRequest->error->context
                    );
                }

                $data['token'] = $tokenRequest->isSuccessful() ? $tokenRequest->response : 'Not found';
            }

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $data['raw'] = $user;
            }

            $list[] = $data;
        }

        return new Response(status: true, response: $list);
    }

    /**
     * Request tokens from plex.tv api.
     *
     * @param Context $context
     * @param int|string $userId
     * @param string $username
     *
     * @return Response
     */
    private function getUserToken(Context $context, int|string $userId, string $username): Response
    {
        try {
            $url = Container::getNew(UriInterface::class)
                ->withPort(443)->withScheme('https')->withHost('plex.tv')
                ->withPath(sprintf('/api/v2/home/users/%s/switch', $userId));

            $this->logger->debug('Requesting temporary access token for [%(backend)] user [%(username)].', [
                'backend' => $context->backendName,
                'username' => $username,
                'user_id' => $userId,
                'url' => (string)$url,
            ]);

            $response = $this->http->request('POST', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $context->backendToken,
                    'X-Plex-Client-Identifier' => $context->backendId,
                ],
            ]);

            if (201 !== $response->getStatusCode()) {
                return new Response(
                    status: false,
                    error:  new Error(
                                message: 'Request for [%(backend)] user [%(username)] temporary access token responded with unexpected [%(status_code)] status code.',
                                context: [
                                             'backend' => $context->backendName,
                                             'username' => $username,
                                             'user_id' => $userId,
                                             'status_code' => $response->getStatusCode(),
                                         ],
                                level:   Levels::ERROR
                            ),
                );
            }

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            if ($context->trace) {
                $this->logger->debug('Parsing temporary access token for [%(backend)] user [%(username)] payload.', [
                    'backend' => $context->backendName,
                    'username' => $username,
                    'user_id' => $userId,
                    'url' => (string)$url,
                    'trace' => $json,
                ]);
            }

            $tempToken = ag($json, 'authToken', null);

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
                ->withPath('/api/v2/resources')->withQuery(
                    http_build_query(
                        [
                            'includeIPv6' => 1,
                            'includeHttps' => 1,
                            'includeRelay' => 1
                        ]
                    )
                );

            $this->logger->debug('Requesting permanent access token for [%(backend)] user [%(username)].', [
                'backend' => $context->backendName,
                'username' => $username,
                'user_id' => $userId,
                'url' => (string)$url,
            ]);

            $response = $this->http->request('GET', (string)$url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $tempToken,
                    'X-Plex-Client-Identifier' => $context->backendId,
                ],
            ]);

            $json = json_decode(
                json:        $response->getContent(),
                associative: true,
                flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            if ($context->trace) {
                $this->logger->debug('Parsing permanent access token for [%(backend)] user [%(username)] payload.', [
                    'backend' => $context->backendName,
                    'username' => $username,
                    'user_id' => $userId,
                    'url' => (string)$url,
                    'trace' => $json,
                ]);
            }

            foreach ($json ?? [] as $server) {
                if (ag($server, 'clientIdentifier') !== $context->backendId) {
                    continue;
                }
                return new Response(status: true, response: ag($server, 'accessToken'));
            }

            return new Response(
                status: false,
                error:  new Error(
                            message: 'No permanent access token found in [%(backend)] user [%(username)] response.',
                            context: [
                                         'backend' => $context->backendName,
                                         'username' => $username,
                                         'user_id' => $userId,
                                     ],
                            level:   Levels::ERROR
                        ),
            );
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error:  new Error(
                            message: 'Unhandled exception was thrown during request for [%(backend)] [%(username)] access token.',
                            context: [
                                         'backend' => $context->backendName,
                                         'username' => $username,
                                         'user_id' => $userId,
                                         'exception' => [
                                             'file' => after($e->getFile(), ROOT_PATH),
                                             'line' => $e->getLine(),
                                             'kind' => get_class($e),
                                             'message' => $e->getMessage(),
                                             'trace' => $context->trace ? $e->getTrace() : [],
                                         ],
                                     ],
                            level:   Levels::ERROR
                        ),
            );
        }
    }
}
