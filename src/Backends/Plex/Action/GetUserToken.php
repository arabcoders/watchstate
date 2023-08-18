<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Container;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class GetUserToken
{
    private int $maxRetry = 3;

    use CommonTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
    {
    }

    /**
     * Get Users list.
     *
     * @param Context $context
     * @param int|string $userId
     * @param string $username
     * @param array $opts optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, int|string $userId, string $username, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->getUserToken($context, $userId, $username)
        );
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
                ->withPath(r('/api/v2/home/users/{user_id}/switch', ['user_id' => $userId]));

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

            if (429 === $response->getStatusCode()) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: 'Request for temporary access token for [%(backend)] user [%(username)] failed due to rate limit. error 429.',
                        context: [
                            'backend' => $context->backendName,
                            'username' => $username,
                            'user_id' => $userId,
                            'status_code' => $response->getStatusCode(),
                            'headers' => $response->getHeaders(),
                        ],
                        level: Levels::ERROR
                    ),
                );
            }

            if (201 !== $response->getStatusCode()) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: 'Request for [%(backend)] user [%(username)] temporary access token responded with unexpected [%(status_code)] status code.',
                        context: [
                            'backend' => $context->backendName,
                            'username' => $username,
                            'user_id' => $userId,
                            'status_code' => $response->getStatusCode(),
                            'headers' => $response->getHeaders(),
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
                $this->logger->debug('Parsing temporary access token for [%(backend)] user [%(username)] payload.', [
                    'backend' => $context->backendName,
                    'username' => $username,
                    'user_id' => $userId,
                    'url' => (string)$url,
                    'trace' => $json,
                    'headers' => $response->getHeaders(),
                ]);
            }

            $tempToken = ag($json, 'authToken', null);

            $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
                ->withPath('/api/v2/resources')->withQuery(
                    http_build_query([
                        'includeIPv6' => 1,
                        'includeHttps' => 1,
                        'includeRelay' => 1
                    ])
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
                json: $response->getContent(),
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
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

            $servers = [];

            foreach ($json ?? [] as $server) {
                if ('server' !== ag($server, 'provides')) {
                    continue;
                }

                $servers[ag($server, 'clientIdentifier')] = ag($server, 'name');

                if (ag($server, 'clientIdentifier') !== $context->backendId) {
                    continue;
                }

                return new Response(status: true, response: ag($server, 'accessToken'));
            }

            $this->logger->error(
                'Response had [%(count)] associated servers, non match [%(backend) - [%(backend_id)] unique identifier.',
                [
                    'count' => count(($json)),
                    'backend' => $context->backendName,
                    'backend_id' => $context->backendId,
                    'servers' => $servers,
                ]
            );

            return new Response(
                status: false,
                error: new Error(
                    message: 'No permanent access token was found for [%(username)] in [%(backend)] response. Likely invalid unique identifier was selected or plex.tv API error, check https://status.plex.tv or try running same command with [-vvv --trace --context] flags for more information.',
                    context: [
                        'backend' => $context->backendName,
                        'username' => $username,
                        'user_id' => $userId,
                    ],
                    level: Levels::ERROR
                ),
            );
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
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
                    level: Levels::ERROR
                ),
            );
        }
    }
}
