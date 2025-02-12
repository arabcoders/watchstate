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
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use App\Libs\Options;
use DateInterval;
use JsonException;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as iException;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;

final class GetUsersList
{
    use CommonTrait;

    private int $maxRetry = 3;
    private string $action = 'plex.getUsersList';
    private iHttp $http;

    public function __construct(iHttp $http, protected iLogger $logger)
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
     * @throws iException
     * @throws JsonException
     */
    private function action(Context $context, array $opts = []): Response
    {
        if (true === (bool)ag($opts, Options::PLEX_EXTERNAL_USER, false)) {
            $cls = fn() => $this->getExternalUsers($context, $opts);
            return true === (bool)ag($opts, Options::NO_CACHE) ? $cls() : $this->tryCache(
                $context,
                $context->backendName . '_external_users_' . md5(json_encode($opts)),
                $cls,
                new DateInterval('PT5M'),
                $this->logger
            );
        }

        $cls = fn() => $this->getHomeUsers($this->getExternalUsers($context, $opts), $context, $opts);

        return true === (bool)ag($opts, Options::NO_CACHE) ? $cls() : $this->tryCache(
            $context,
            $context->backendName . '_users_' . md5(json_encode($opts)),
            $cls,
            new DateInterval('PT5M'),
            $this->logger
        );
    }

    /**
     * Get Home Users.
     *
     * @param Response $users External users.
     * @param Context $context The context.
     * @param array $opts The options.
     *
     * @return Response Return the response.
     * @throws iException if an error occurs during the request.
     * @throws JsonException if an error occurs during the JSON parsing.
     */
    private function getHomeUsers(Response $users, Context $context, array $opts = []): Response
    {
        $url = Container::getNew(iUri::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
            ->withPath('/api/v2/home/users/');

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            $url = $url->withQuery(http_build_query(['pin' => $pin]));
        }

        $this->logger->debug("Requesting '{user}@{backend}' users list.", [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        try {
            $response = $this->request($url, $context, opts: [
                'headers' => [
                    'Accept' => 'application/json',
                ]
            ]);
        } catch (InvalidArgumentException $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: $e->getMessage(),
                    context: [
                        'user' => $context->userContext->name,
                        'backend' => $context->backendName,
                    ],
                    level: Levels::ERROR,
                    previous: $e
                ),
            );
        }

        return $this->processHomeUsers($users, $context, $url, $response, $opts);
    }

    /**
     * Get Users list.
     *
     * @throws iException
     * @throws JsonException
     */
    private function getExternalUsers(Context $context, array $opts = []): Response
    {
        $url = Container::getNew(iUri::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
            ->withPath('/api/users/');

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            $url = $url->withQuery(http_build_query(['pin' => $pin]));
        }

        $this->logger->debug("Requesting '{user}@{backend}' external users list.", [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        try {
            $users = $this->processExternalUsers($this->request($url, $context, opts: [
                'headers' => [
                    'Accept' => 'application/xml',
                ]
            ]), $context, $url);

            if (true !== (bool)ag($opts, Options::GET_TOKENS) || count($users) < 1) {
                return new Response(status: true, response: $users);
            }
        } catch (InvalidArgumentException $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: $e->getMessage(),
                    context: [
                        'user' => $context->userContext->name,
                        'backend' => $context->backendName,
                    ],
                    level: Levels::ERROR,
                    previous: $e
                ),
            );
        }

        $url = Container::getNew(iUri::class)->withPort(443)->withScheme('https')->withHost('plex.tv')
            ->withPath(r('/api/servers/{backendId}/shared_servers', ['backendId' => $context->backendId]));

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            $url = $url->withQuery(http_build_query(['pin' => $pin]));
        }

        $this->logger->debug("Requesting '{user}@{backend}' external users accesstokens.", [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        try {
            $response = $this->request($url, $context, opts: [
                'headers' => [
                    'Accept' => 'application/xml',
                ]
            ]);
        } catch (InvalidArgumentException $e) {
            return new Response(
                status: false,
                error: new Error(
                    message: $e->getMessage(),
                    context: [
                        'user' => $context->userContext->name,
                        'backend' => $context->backendName,
                    ],
                    level: Levels::ERROR,
                    previous: $e
                ),
            );
        }

        return $this->processUsersTokens($users, $context, $url, $response);
    }

    /**
     * Process external users list response.
     *
     * @param iResponse $response
     * @param Context $context
     * @param iUri $url
     *
     * @return array Return processed response.
     * @throws iException if an error occurs during the request.
     * @throws JsonException if an error occurs during the JSON parsing.
     */
    private function processExternalUsers(iResponse $response, Context $context, iUri $url): array
    {
        $data = json_decode(
            json: json_encode(simplexml_load_string($response->getContent(false))),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug("Parsing '{user}@{backend}' external users list payload.", [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $data,
            ]);
        }

        $list = [];

        foreach (ag($data, 'User', []) as $data) {
            $user = ag($data, '@attributes', []);
            $uuidStatus = preg_match('/\/users\/(?<uuid>.+?)\/avatar/', ag($user, 'thumb', ''), $matches);

            $list[] = [
                'id' => ag($user, 'id'),
                'uuid' => 1 === $uuidStatus ? ag($matches, 'uuid') : ag($user, 'invited_user'),
                'name' => ag($user, ['username', 'title', 'email'], '??'),
                'admin' => false,
                'guest' => 1 !== (int)ag($user, 'home'),
                'restricted' => 1 === (int)ag($user, 'restricted'),
                'updatedAt' => 'external_user',
            ];
        }

        return $list;
    }

    /**
     * Process external users access tokens.
     *
     * @param array $users List of users.
     * @param Context $context The context.
     * @param iUri $url The URL.
     * @param iResponse $response The response.
     *
     * @return Response Return processed response.
     * @throws iException if an error occurs during the request.
     * @throws JsonException if an error occurs during the JSON parsing.
     */
    private function processUsersTokens(array $users, Context $context, iUri $url, iResponse $response): Response
    {
        if (count($users) < 1) {
            return new Response(status: true, response: $users);
        }

        $json = json_decode(
            json: json_encode(simplexml_load_string($response->getContent(false))),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug("Parsing '{user}@{backend}' users list payload.", [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        foreach (ag($json, 'SharedServer', []) as $data) {
            $data = ag($data, '@attributes', []);

            foreach ($users as &$user) {
                if ((int)ag($user, 'id') !== (int)ag($data, 'userID')) {
                    continue;
                }
                $user['token'] = ag($data, 'accessToken');
                $user['updatedAt'] = isset($user['invitedAt']) ? makeDate($user['invitedAt']) : 'external_user';
            }
        }

        return new Response(status: true, response: $users);
    }

    /**
     * Process home-users response.
     *
     * @param Response $users External users.
     * @param Context $context The context.
     * @param iUri $url The URL.
     * @param iResponse $response The response.
     * @param array $opts The options.
     *
     * @return Response Return processed response.
     * @throws iException if an error occurs during the request.
     * @throws JsonException if an error occurs during the JSON parsing.
     */
    private function processHomeUsers(
        Response $users,
        Context $context,
        iUri $url,
        iResponse $response,
        array $opts
    ): Response {
        $json = json_decode(
            json: $response->getContent(false),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug("Parsing '{user}@{backend}' home users list payload.", [
                'user' => $context->userContext->name,
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        if ($users->hasError()) {
            $this->logger->log($users->error->level(), $users->error->message, $users->error->context);
        }

        $users = $users->isSuccessful() ? $users->response : [];

        $list = [];

        foreach (ag($json, 'users', []) as $data) {
            foreach ($users as &$user) {
                if ((int)ag($user, 'id') !== (int)ag($data, 'id')) {
                    continue;
                }
                $user['updatedAt'] = isset($data['updatedAt']) ? makeDate($data['updatedAt']) : 'Never';
            }

            if (true !== (bool)ag($data, 'admin')) {
                continue;
            }

            $data = [
                'id' => ag($data, 'id'),
                'uuid' => ag($data, 'uuid'),
                'name' => ag($data, ['friendlyName', 'username', 'title', 'email'], '??'),
                'admin' => (bool)ag($data, 'admin'),
                'guest' => (bool)ag($data, 'guest'),
                'restricted' => (bool)ag($data, 'restricted'),
                'updatedAt' => isset($data['updatedAt']) ? makeDate($data['updatedAt']) : 'Never',
            ];

            if (true === (bool)ag($opts, Options::GET_TOKENS)) {
                $tokenRequest = Container::getNew(GetUserToken::class)(
                    context: $context,
                    userId: ag($data, 'uuid'),
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

            $list[] = $data;
        }

        array_push($list, ...$users);

        return new Response(status: true, response: $list);
    }

    /**
     * Do the actual API request.
     *
     * @param iUri $url The URL.
     * @param Context $context The context.
     * @param array $opts The options.
     *
     * @return iResponse Return the response.
     * @throws iException if an error occurs during the request.
     * @throws InvalidArgumentException if the request returns an unexpected status code.
     */
    private function request(iUri $url, Context $context, array $opts = []): iResponse
    {
        if (null !== ($adminToken = ag($context->options, Options::ADMIN_TOKEN))) {
            if (null !== ($adminPin = ag($context->options, Options::ADMIN_PLEX_USER_PIN))) {
                parse_str($url->getQuery(), $query);
                $url = $url->withQuery(http_build_query(['pin' => $adminPin, ...$query,]));
            }
            $response = $this->http->request(ag($opts, 'method', 'GET'), (string)$url, [
                'headers' => array_replace_recursive([
                    'X-Plex-Token' => $adminToken,
                    'X-Plex-Client-Identifier' => $context->backendId,
                ], ag($opts, 'headers', [])),
            ]);
            if (Status::OK === Status::from($response->getStatusCode())) {
                return $response;
            }
        }
        
        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            parse_str($url->getQuery(), $query);
            $url = $url->withQuery(http_build_query(['pin' => $pin, ...$query,]));
        }

        $response = $this->http->request(ag($opts, 'method', 'GET'), (string)$url, [
            'headers' => array_replace_recursive([
                'X-Plex-Token' => $context->backendToken,
                'X-Plex-Client-Identifier' => $context->backendId,
            ], ag($opts, 'headers', [])),
        ]);

        if (Status::OK === Status::from($response->getStatusCode())) {
            return $response;
        }

        throw new InvalidArgumentException(
            r(
                "Request for '{user}@{backend}' users list returned with unexpected '{status_code}' status code. {tokenType}",
                [
                    'user' => $context->userContext->name,
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    'body' => $response->getContent(false),
                    'tokenType' => ag_exists(
                        $context->options,
                        Options::ADMIN_TOKEN
                    ) ? 'user & admin token' : 'user token',
                ]
            )
        );
    }
}
