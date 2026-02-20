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
use App\Libs\Extends\RetryableHttpClient;
use App\Libs\Options;
use Closure;
use DateInterval;
use JsonException;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as iException;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface as iResponse;
use Throwable;

final class GetUsersList
{
    use CommonTrait;

    private int $maxRetry = 3;
    private string $action = 'plex.getUsersList';
    private iHttp $http;

    private bool $logRequests = false;

    private array $rawRequests = [];

    public function __construct(
        iHttp $http,
        protected iLogger $logger,
    ) {
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
            action: $this->action,
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
        $callback = ag($opts, Options::RAW_RESPONSE_CALLBACK, null);
        $this->logRequests = $callback && ag($opts, Options::RAW_RESPONSE, false);

        $opts[Options::LOG_TO_WRITER] = ag($opts, Options::LOG_TO_WRITER, static fn() => static function (string $log) {});

        if (true === (bool) ag($opts, Options::PLEX_EXTERNAL_USER, false)) {
            $cls = fn() => $this->getExternalUsers($context, $opts);
            $opts[Options::LOG_TO_WRITER](r('Reading external user from cache? {state}', [
                'state' => true === (bool) ag($opts, Options::NO_CACHE) ? 'no' : 'yes',
            ]));

            return true === (bool) ag($opts, Options::NO_CACHE)
                ? $cls()
                : $this->tryCache(
                    $context,
                    $context->backendName . '_' . $context->backendId . '_external_users_'
                        . md5(
                            (string) json_encode($opts),
                        ),
                    $cls,
                    new DateInterval('PT5M'),
                    $this->logger,
                );
        }

        $cls = fn() => $this->getHomeUsers($this->getExternalUsers($context, $opts), $context, $opts);

        $opts[Options::LOG_TO_WRITER](r('Reading data from cache? {state}', [
            'state' => true === (bool) ag($opts, Options::NO_CACHE) ? 'no' : 'yes',
        ]));

        $data = true === (bool) ag($opts, Options::NO_CACHE)
            ? $cls()
            : $this->tryCache(
                $context,
                $context->backendName . '_' . $context->backendId . '_users_' . md5((string) json_encode($opts)),
                $cls,
                new DateInterval('PT5M'),
                $this->logger,
            );

        if (count($this->rawRequests) > 0 && $callback instanceof Closure) {
            $callback($this->rawRequests);
        }

        return $data;
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
        $url = Container::getNew(iUri::class)
            ->withPort(443)
            ->withScheme('https')
            ->withHost('plex.tv')
            ->withPath('/api/v2/home/users/');

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            $url = $url->withQuery(http_build_query(['pin' => $pin]));
        }

        $this->logger->debug("Requesting '{user}@{backend}' users list.", [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
            'url' => (string) $url,
        ]);

        try {
            $response = $this->request($url, $context, opts: [
                'headers' => [
                    'Accept' => 'application/json',
                ],
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
                    previous: $e,
                ),
            );
        }

        return $this->processHomeUsers($users, $context, $url, $response, $opts);
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
        array $opts,
    ): Response {
        $json = json_decode(
            json: $response->getContent(false),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        if ($this->logRequests) {
            $this->rawRequests[] = [
                'url' => (string) $url,
                'headers' => $response->getHeaders(false),
                'body' => $json,
            ];
        }

        if ($context->trace) {
            $this->logger->debug("Parsing '{user}@{backend}' home users list payload.", [
                'user' => $context->userContext->name,
                'backend' => $context->backendName,
                'url' => (string) $url,
                'trace' => $json,
            ]);
        }

        if ($users->hasError() && $users->error) {
            $this->logger->log($users->error->level(), $users->error->message, $users->error->context);
        }

        $external = $users->isSuccessful() ? $users->response : [];

        $list = [];

        foreach (ag($json, 'users', []) as $data) {
            // -- update external user updatedAt.
            foreach ($external as &$user) {
                if ((int) ag($user, 'id') !== (int) ag($data, 'id')) {
                    continue;
                }
                $user['updatedAt'] = isset($data['updatedAt']) ? make_date($data['updatedAt']) : 'Never';
            }

            $data = [
                'id' => ag($data, 'id'),
                'type' => 'H',
                'uuid' => ag($data, 'uuid'),
                'name' => normalize_name(ag($data, ['friendlyName', 'username', 'title', 'email', 'id'], '??')),
                'admin' => (bool) ag($data, 'admin'),
                'guest' => (bool) ag($data, 'guest'),
                'restricted' => (bool) ag($data, 'restricted'),
                'protected' => (bool) ag($data, 'protected'),
                'updatedAt' => isset($data['updatedAt']) ? make_date($data['updatedAt']) : 'Unknown',
            ];

            if (true === (bool) ag($opts, Options::GET_TOKENS)) {
                $tokenRequest = Container::getNew(GetUserToken::class)(
                    context: $context,
                    userId: ag($data, 'uuid'),
                    username: ag($data, 'name'),
                );

                if ($tokenRequest->hasError() && $tokenRequest->error) {
                    $this->logger->log(
                        $tokenRequest->error->level(),
                        $tokenRequest->error->message,
                        $tokenRequest->error->context,
                    );
                }

                $data['token'] = $tokenRequest->isSuccessful() ? $tokenRequest->response : null;
                if (true === $tokenRequest->hasError() && $tokenRequest->error) {
                    $data['token_error'] = ag($tokenRequest->error->extra, 'error', $tokenRequest->error->format());
                }
            }

            $list[] = $data;
        }

        $opts[Options::LOG_TO_WRITER](r("Total '{count}' home users processed. {users}", [
            'users' => array_to_json($list),
            'count' => count($list),
        ]));

        /**
         * De-duplicate users.
         * Plex in their infinite wisdom sometimes return home users as external users.
         */
        foreach ($external as $user) {
            if (
                null !== ($homeUser = array_find(
                    $list,
                    static fn($u) => (int) $u['id'] === (int) $user['id'] && $u['name'] === $user['name'],
                ))
            ) {
                $opts[Options::LOG_TO_WRITER](r("Skipping external user '{name}' with id '{id}' because match a home user with id '{userId}' and name '{userName}'.", [
                    'id' => ag($user, 'id'),
                    'name' => ag($user, 'name'),
                    'userId' => ag($homeUser, 'id'),
                    'userName' => ag($homeUser, 'name'),
                ]));
                continue;
            }

            $list[] = $user;
        }

        return new Response(status: true, response: $list);
    }

    /**
     * Get Plex External Users.
     *
     * @throws iException
     * @throws JsonException
     */
    private function getExternalUsers(Context $context, array $opts = []): Response
    {
        if (true === (bool) ag($context->options, Options::PLEX_GUEST_USER, false)) {
            return new Response(status: true, response: []);
        }

        $url = Container::getNew(iUri::class)
            ->withPort(443)
            ->withScheme('https')
            ->withHost('plex.tv')
            ->withPath('/api/users/');

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            $url = $url->withQuery(http_build_query(['pin' => $pin]));
        }

        $this->logger->debug("Requesting '{user}@{backend}' external users list.", [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
            'url' => (string) $url,
        ]);

        try {
            $users = $this->processExternalUsers($this->request($url, $context, opts: [
                'headers' => [
                    'Accept' => 'application/xml',
                ],
            ]), $context, $url, $opts);

            if (true !== (bool) ag($opts, Options::GET_TOKENS) || count($users) < 1) {
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
                    previous: $e,
                ),
            );
        }

        $url = Container::getNew(iUri::class)
            ->withPort(443)
            ->withScheme('https')
            ->withHost('plex.tv')
            ->withPath(r('/api/servers/{backendId}/shared_servers', ['backendId' => $context->backendId]));

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            $url = $url->withQuery(http_build_query(['pin' => $pin]));
        }

        $this->logger->debug("Requesting '{user}@{backend}' external users access-tokens.", [
            'user' => $context->userContext->name,
            'backend' => $context->backendName,
            'url' => (string) $url,
        ]);

        try {
            $response = $this->request($url, $context, opts: [
                'headers' => [
                    'Accept' => 'application/xml',
                ],
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
                    previous: $e,
                ),
            );
        }

        return $this->externalUsersTokens($users, $context, $url, $response, $opts);
    }

    /**
     * Process external users list response.
     *
     * @param iResponse $response
     * @param Context $context
     * @param iUri $url
     * @param array $opts The options.
     *
     * @return array Return processed response.
     * @throws iException if an error occurs during the request.
     */
    private function processExternalUsers(iResponse $response, Context $context, iUri $url, array $opts = []): array
    {
        $content = simplexml_load_string($response->getContent(false));
        $data = [];
        foreach ($content->User ?? [] as $_user) {
            $user = [];
            // @INFO: This workaround is needed, for some reason array_map() doesn't work correctly on xml objects.
            foreach ($_user->attributes() as $k => $v) {
                $user[$k] = (string) $v;
            }

            $data[] = $user;
        }

        if ($this->logRequests) {
            $this->rawRequests[] = [
                'url' => (string) $url,
                'headers' => $response->getHeaders(false),
                'body' => json_decode(json_encode($content), true),
            ];
        }

        if ($context->trace) {
            $this->logger->debug("Parsing '{user}@{backend}' external users list payload.", [
                'backend' => $context->backendName,
                'url' => (string) $url,
                'trace' => $data,
            ]);
        }

        $list = [];
        foreach ($data as $user) {
            $uuidStatus = preg_match('/\/users\/(?<uuid>.+?)\/avatar/', ag($user, 'thumb', ''), $matches);
            $_user = [
                'id' => (int) ag($user, 'id'),
                'type' => 'E',
                'uuid' => 1 === $uuidStatus ? ag($matches, 'uuid') : ag($user, 'invited_user'),
                'name' => normalize_name(ag($user, ['username', 'title', 'email', 'id'], '??')),
                'admin' => false,
                'guest' => 1 !== (int) ag($user, 'home'),
                'restricted' => 1 === (int) ag($user, 'restricted'),
                'protected' => 1 === (int) ag($user, 'protected'),
                'updatedAt' => 'external_user',
            ];

            $list[] = $_user;

            $opts[Options::LOG_TO_WRITER](r("Processed external user '{name}' with id '{id}': {data}.", [
                'name' => $_user['name'],
                'id' => $_user['id'],
                'data' => [
                    'local' => array_to_json($_user),
                    'remote' => array_to_json($user),
                ],
            ]));
        }

        $opts[Options::LOG_TO_WRITER](r("Total '{count}' external users processed. {users}", [
            'users' => array_to_json($list),
            'count' => count($list),
        ]));

        return $list;
    }

    /**
     * Process external users access tokens.
     *
     * @param array $users List of users.
     * @param Context $context The context.
     * @param iUri $url The URL.
     * @param iResponse $response The response.
     * @param array $opts The options.
     *
     * @return Response Return processed response.
     * @throws iException if an error occurs during the request.
     * @throws JsonException if an error occurs during the JSON parsing.
     */
    private function externalUsersTokens(array $users, Context $context, iUri $url, iResponse $response, array $opts = []): Response
    {
        if (count($users) < 1) {
            return new Response(status: true, response: $users);
        }

        $json = json_decode(
            json: json_encode(simplexml_load_string($response->getContent(false))),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        if ($this->logRequests) {
            $this->rawRequests[] = [
                'url' => (string) $url,
                'headers' => $response->getHeaders(false),
                'body' => $json,
            ];
        }

        if ($context->trace) {
            $this->logger->debug("Parsing '{user}@{backend}' users list payload.", [
                'backend' => $context->backendName,
                'url' => (string) $url,
                'trace' => $json,
            ]);
        }

        foreach (ag($json, 'SharedServer', []) as $data) {
            $data = ag($data, '@attributes', []);

            foreach ($users as &$user) {
                if ((int) ag($user, 'id') !== (int) ag($data, 'userID')) {
                    $opts[Options::LOG_TO_WRITER](r("Skipping token for user '{name}' with id '{id}' because it doesn't match with userID '{userID}' in the response.", [
                        'name' => ag($user, 'name'),
                        'id' => ag($user, 'id'),
                        'userID' => ag($data, 'userID'),
                    ]));
                    continue;
                }
                $user['token'] = ag($data, 'accessToken');
                $user['updatedAt'] = isset($user['invitedAt']) ? make_date($user['invitedAt']) : 'external_user';
            }
        }

        return new Response(status: true, response: $users);
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
                $url = $url->withQuery(http_build_query(['pin' => $adminPin, ...$query]));
            }
            $response = $this->http->request(ag($opts, 'method', 'GET'), (string) $url, [
                'headers' => array_replace_recursive(
                    [
                        'X-Plex-Token' => $adminToken,
                        'X-Plex-Client-Identifier' => $context->backendId,
                    ],
                    ag($opts, 'headers', []),
                ),
            ]);
            if (Status::OK === Status::from($response->getStatusCode())) {
                return $response;
            }
        }

        if (null !== ($pin = ag($context->options, Options::PLEX_USER_PIN))) {
            parse_str($url->getQuery(), $query);
            $url = $url->withQuery(http_build_query(['pin' => $pin, ...$query]));
        }

        $response = $this->http->request(ag($opts, 'method', 'GET'), (string) $url, [
            'headers' => array_replace_recursive(
                [
                    'X-Plex-Token' => $context->backendToken,
                    'X-Plex-Client-Identifier' => $context->backendId,
                ],
                ag($opts, 'headers', []),
            ),
        ]);

        if (Status::OK === Status::from($response->getStatusCode())) {
            return $response;
        }

        $extra_msg = '';

        try {
            $extra_msg = ag($response->toArray(false), 'errors.0.message', '?');
        } catch (Throwable) {
        }

        throw new InvalidArgumentException(
            r(
                "Request for '{user}@{backend}' users list returned with unexpected '{status_code}' status code. {tokenType}{extra_msg}",
                [
                    'user' => $context->userContext->name,
                    'backend' => $context->backendName,
                    'status_code' => $response->getStatusCode(),
                    'body' => $response->getContent(false),
                    'extra_msg' => !$extra_msg ? '' : ". {$extra_msg}",
                    'tokenType' => ag_exists(
                        $context->options,
                        Options::ADMIN_TOKEN,
                    )
                        ? 'user & admin token'
                        : 'user token',
                ],
            ),
        );
    }
}
