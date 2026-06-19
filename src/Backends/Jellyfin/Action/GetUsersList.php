<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

/**
 * Class GetUsersList
 *
 * This class is responsible for retrieving the users list from Jellyfin API.
 */
class GetUsersList
{
    use CommonTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.getUsersList';

    /**
     * Class Constructor.
     *
     * @param iHttp&\App\Libs\Extends\HttpClient $http The HTTP client instance.
     * @param iLogger $logger The logger instance.
     */
    public function __construct(
        protected iHttp $http,
        protected iLogger $logger,
    ) {}

    /**
     * Get Users list.
     *
     * @param Context $context The context instance.
     * @param array $opts Additional options.
     *
     * @return Response The response received.
     */
    public function __invoke(Context $context, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->getUsers($context, $opts),
            action: $this->action,
        );
    }

    /**
     * Fetch the users list from Jellyfin API.
     *
     * @throws ExceptionInterface When the request fails.
     * @throws JsonException When the response is not a valid JSON.
     */
    private function getUsers(Context $context, array $opts = []): Response
    {
        if ($context->isLimitedToken(true) && false === (bool) ag($opts, Options::NO_FALLBACK, false)) {
            $limited = Container::get(GetUser::class)($context);
            if ($limited->isSuccessful()) {
                return new Response(status: true, response: [$limited->response]);
            }
            return $limited;
        }

        $callback = ag($opts, Options::RAW_RESPONSE_CALLBACK, null);
        $logRequests = $callback && ag($opts, Options::RAW_RESPONSE, false);

        $url = $context->backendUrl->withPath('/Users/');

        $logContext = [
            'action' => $this->action,
            'identity' => [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
            ],
            'request' => ['url' => (string) $url],
        ];

        $this->logger->debug("Requesting '{identity.user}@{identity.backend}' users list.", $logContext);

        $headers = $context->getHttpOptions();

        if (empty($headers)) {
            $headers = [
                'headers' => [
                    'X-MediaBrowser-Token' => $context->backendToken,
                ],
            ];
        }

        $response = $this->http->request(Method::GET, (string) $url, $headers);

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            $body = $response->getContent(false);
            $reason = $this->getBackendResponseReason($body);

            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{identity.user}@{identity.backend}' users list returned with unexpected '{response.status_code}' status code.",
                    context: [
                        ...$logContext,
                        'response' => [
                            'status_code' => $response->getStatusCode(),
                            'body' => $body,
                            'reason' => $reason,
                        ],
                    ],
                    level: Levels::ERROR,
                    extra: array_filter([
                        'error' => $reason,
                    ]),
                ),
            );
        }

        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        if ($context->trace) {
            $this->logger->debug("Parsing '{identity.user}@{identity.backend}' user list payload.", [
                ...$logContext,
                'response' => ['body' => $json],
            ]);
        }

        $list = [];

        foreach ($json ?? [] as $user) {
            $date = ag($user, ['LastActivityDate', 'LastLoginDate'], null);

            $data = [
                'id' => ag($user, 'Id'),
                'name' => ag($user, 'Name'),
                'admin' => (bool) ag($user, 'Policy.IsAdministrator'),
                'hidden' => (bool) ag($user, 'Policy.IsHidden'),
                'disabled' => (bool) ag($user, 'Policy.IsDisabled'),
                'updatedAt' => null !== $date ? make_date($date) : 'Never',
            ];

            if (true === (bool) ag($opts, 'tokens')) {
                $data['token'] = $context->backendToken;
            }

            $list[] = $data;
        }

        if ($logRequests) {
            $callback([[
                'request' => ['url' => (string) $url],
                'headers' => $response->getHeaders(false),
                'body' => $json,
            ]]);
        }

        return new Response(status: true, response: $list);
    }
}
