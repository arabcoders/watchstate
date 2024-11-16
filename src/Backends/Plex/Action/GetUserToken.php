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
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Throwable;

final class GetUserToken
{
    use CommonTrait;

    private int $maxRetry = 3;
    private string $action = 'plex.getUserToken';
    private iHttp $http;

    public function __construct(iHttp $http, protected LoggerInterface $logger)
    {
        $this->http = new RetryableHttpClient(client: $http, maxRetries: $this->maxRetry, logger: $this->logger);
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
            fn: fn() => $this->getUserToken($context, $userId, $username),
            action: $this->action,
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

            $pin = ag($context->options, Options::PLEX_USER_PIN);

            $this->logger->debug('Requesting temporary access token for [{backend}] user [{username}]{pin}', [
                'backend' => $context->backendName,
                'username' => $username,
                'user_id' => $userId,
                'url' => (string)$url,
                'pin' => null !== $pin ? ' with PIN.' : '.',
            ]);

            if (null !== $pin) {
                $url = $url->withQuery(http_build_query(['pin' => $pin]));
            }

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
                        message: "Request for temporary access token for '{backend}' user '{username}'{pin} failed due to rate limit. error 429.",
                        context: [
                            'backend' => $context->backendName,
                            'username' => $username,
                            'user_id' => $userId,
                            'status_code' => $response->getStatusCode(),
                            'headers' => $response->getHeaders(),
                            'pin' => null !== $pin ? ' with pin' : '',
                        ],
                        level: Levels::ERROR
                    ),
                );
            }

            if (201 !== $response->getStatusCode()) {
                return new Response(
                    status: false,
                    error: new Error(
                        message: "Request for '{backend}' user '{username}'{pin} temporary access token responded with unexpected '{status_code}' status code.",
                        context: [
                            'backend' => $context->backendName,
                            'username' => $username,
                            'user_id' => $userId,
                            'status_code' => $response->getStatusCode(),
                            'headers' => $response->getHeaders(),
                            'pin' => null !== $pin ? ' with pin' : '',
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
                $this->logger->debug("Parsing temporary access token for '{backend}' user '{username}'{pin} payload.", [
                    'backend' => $context->backendName,
                    'username' => $username,
                    'user_id' => $userId,
                    'url' => (string)$url,
                    'trace' => $json,
                    'headers' => $response->getHeaders(),
                    'pin' => null !== $pin ? ' with pin' : '',
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

            $this->logger->debug("Requesting permanent access token for '{backend}' user '{username}'{pin}.", [
                'backend' => $context->backendName,
                'username' => $username,
                'user_id' => $userId,
                'url' => (string)$url,
                'pin' => null !== $pin ? ' with pin' : '',
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
                $this->logger->debug("Parsing permanent access token for '{backend}' user '{username}'{pin} payload.", [
                    'backend' => $context->backendName,
                    'username' => $username,
                    'user_id' => $userId,
                    'url' => (string)$url,
                    'trace' => $json,
                    'pin' => null !== $pin ? ' with pin' : '',
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
                "Response had '{count}' associated servers, non match '{backend}: {backend_id}' unique identifier.",
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
                    message: "No permanent access token was found for '{username}'{pin} in '{backend}' response. Likely invalid unique identifier was selected or plex.tv API error, check https://status.plex.tv or try running same command with [--debug] flag for more information.",
                    context: [
                        'backend' => $context->backendName,
                        'username' => $username,
                        'user_id' => $userId,
                        'pin' => null !== $pin ? ' with pin' : '',
                    ],
                    level: Levels::ERROR
                ),
            );
        } catch (Throwable $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {backend}' request for '{username}'{pin} access token. Error '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'backend' => $context->backendName,
                        'client' => $context->clientName,
                        'pin' => isset($pin) ? ' with pin' : '',
                        'error' => [
                            'kind' => $e::class,
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'file' => after($e->getFile(), ROOT_PATH),
                        ],
                        'username' => $username,
                        'user_id' => $userId,
                        'exception' => [
                            'file' => after($e->getFile(), ROOT_PATH),
                            'line' => $e->getLine(),
                            'kind' => get_class($e),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ],
                    level: Levels::ERROR,
                    previous: $e
                ),
            );
        }
    }
}
