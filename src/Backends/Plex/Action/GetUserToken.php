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
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Options;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as iException;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
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
        if (true === (bool)ag($opts, Options::PLEX_EXTERNAL_USER, false)) {
            $fn = fn() => $this->GetExternalUserToken($context, $userId, $username, $opts);
        } else {
            $fn = fn() => $this->getUserToken($context, $userId, $username);
        }

        return $this->tryResponse(context: $context, fn: $fn, action: $this->action);
    }

    /**
     * Request accesstoken from plex.tv api.
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
            $url = Container::getNew(iUri::class)
                ->withPort(443)->withScheme('https')->withHost('plex.tv')
                ->withPath(r('/api/v2/home/users/{user_id}/switch', ['user_id' => $userId]));

            $this->logger->debug("Requesting temporary access token for '{user}@{backend}' user '{username}'.", [
                'user' => $context->userContext->name,
                'backend' => $context->backendName,
                'username' => $username,
                'user_id' => $userId,
                'url' => (string)$url,
            ]);

            $response = $this->request(Method::POST, $url, Status::CREATED, $context, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if (true === ($response instanceof Response)) {
                return $response;
            }

            $json = json_decode(
                json: $response->getContent(),
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            if ($context->trace) {
                $this->logger->debug("Parsing temporary access token for '{user}@{backend}' user '{username}'.", [
                    'user' => $context->userContext->name,
                    'backend' => $context->backendName,
                    'username' => $username,
                    'user_id' => $userId,
                    'url' => (string)$url,
                    'trace' => $json,
                    'headers' => $response->getHeaders(),
                ]);
            }

            $tempToken = ag($json, 'authToken', null);

            $url = Container::getNew(iUri::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
                ->withPath('/api/v2/resources')
                ->withQuery(http_build_query(['includeIPv6' => 1, 'includeHttps' => 1, 'includeRelay' => 1]));

            $this->logger->debug("Requesting permanent access token for '{user}@{backend}' user '{username}'.", [
                'user' => $context->userContext->name,
                'backend' => $context->backendName,
                'username' => $username,
                'user_id' => $userId,
                'url' => (string)$url,
            ]);

            $response = $this->request(Method::GET, $url, Status::OK, $context, [
                'no_admin' => true,
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Plex-Token' => $tempToken,
                ],
            ]);

            $json = json_decode(
                json: $response->getContent(),
                associative: true,
                flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
            );

            if ($context->trace) {
                $this->logger->debug(
                    "Parsing permanent access token for '{user}@{backend}' user '{username}' payload.",
                    [
                        'user' => $context->userContext->name,
                        'backend' => $context->backendName,
                        'username' => $username,
                        'user_id' => $userId,
                        'url' => (string)$url,
                        'trace' => $json,
                    ]
                );
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
                "Response had '{count}' associated servers, non match '{user}@{backend}: {backend_id}' unique identifier.",
                [
                    'count' => count(($json)),
                    'user' => $context->userContext->name,
                    'backend' => $context->backendName,
                    'backend_id' => $context->backendId,
                    'servers' => $servers,
                ]
            );

            return new Response(
                status: false,
                error: new Error(
                    message: "No permanent access token was found for '{username}' in '{user}@{backend}' response. Likely invalid unique identifier was selected or plex.tv API error, check https://status.plex.tv or try running same command with [--debug] flag for more information.",
                    context: [
                        'user' => $context->userContext->name,
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
                    message: "Exception '{error.kind}' was thrown unhandled during '{client}: {user}@{backend}' request for '{username}'{pin} access token. Error '{error.message}' at '{error.file}:{error.line}'.",
                    context: [
                        'user' => $context->userContext->name,
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

    /**
     * Get external user accesstoken.
     *
     * @param Context $context
     * @param int|string $userId
     * @param string $username
     *
     * @return Response
     */
    private function GetExternalUserToken(Context $context, int|string $userId, string $username): Response
    {
        $class = Container::get(GetUsersList::class);
        $response = $class(context: $context, opts: [
            Options::PLEX_EXTERNAL_USER => true,
            Options::GET_TOKENS => true,
        ]);

        if ($response->hasError()) {
            return $response;
        }

        foreach ($response->response as $user) {
            if ($userId !== ag($user, 'id') && $username !== ag($user, 'username') && $userId !== ag($user, 'uuid')) {
                continue;
            }

            return new Response(status: true, response: ag($user, 'token'));
        }

        return new Response(
            status: false,
            error: new Error(
                message: "Failed to generate '{user}@{backend}'. '{userId}:{username}' accesstoken.",
                context: [
                    'user' => $context->userContext->name,
                    'backend' => $context->backendName,
                    'userId' => $userId,
                    'username' => $username,
                ],
                level: Levels::ERROR
            ),
        );
    }

    /**
     * Do the actual API request.
     *
     * @param Method $method The method.
     * @param iUri $url The URL.
     * @param Status $expectedStatus The expected status.
     * @param Context $context The context.
     * @param array $opts The options.
     *
     * @return iResponse|Response Return {@see iResponse} the response if successful. return {@see Response} if failed.
     * @throws iException if an error occurs during the request.
     */
    private function request(
        Method $method,
        iUri $url,
        Status $expectedStatus,
        Context $context,
        array $opts = []
    ): iResponse|Response {
        if (true !== ag($opts, 'no_admin') && null !== ($adminToken = ag($context->options, Options::ADMIN_TOKEN))) {
            if (null !== ($adminPin = ag($context->options, Options::ADMIN_PLEX_USER_PIN))) {
                parse_str($url->getQuery(), $query);
                $url = $url->withQuery(http_build_query(['pin' => $adminPin, ...$query,]));
            }

            $response = $this->http->request($method->value, (string)$url, [
                'headers' => array_replace_recursive([
                    'X-Plex-Token' => $adminToken,
                    'X-Plex-Client-Identifier' => $context->backendId,
                ], ag($opts, 'headers', [])),
                ...ag($opts, 'options', []),
            ]);
            if ($expectedStatus === Status::from($response->getStatusCode())) {
                return $response;
            }
        }

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            parse_str($url->getQuery(), $query);
            $url = $url->withQuery(http_build_query(['pin' => $pin, ...$query,]));
        }

        $response = $this->http->request($method->value, (string)$url, [
            'headers' => array_replace_recursive([
                'X-Plex-Token' => $context->backendToken,
                'X-Plex-Client-Identifier' => $context->backendId,
            ], ag($opts, 'headers', [])),
            ...ag($opts, 'options', []),
        ]);

        if ($expectedStatus === Status::from($response->getStatusCode())) {
            return $response;
        }

        return new Response(
            status: false,
            error: new Error(
                message: "Request for '{user}@{backend}' users list returned with unexpected '{status_code}' status code. {tokenType}",
                context: [
                    'user' => $context->userContext->name,
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    'body' => $response->getContent(false),
                    'tokenType' => ag_exists(
                        $context->options,
                        Options::ADMIN_TOKEN
                    ) ? 'user & admin token' : 'user token',
                    'response' => $response,
                ],
                level: Levels::ERROR
            )
        );
    }
}
