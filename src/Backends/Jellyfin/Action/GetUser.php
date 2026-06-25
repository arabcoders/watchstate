<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Config;
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
class GetUser
{
    use CommonTrait;

    /**
     * @var string Action name.
     */
    protected string $action = 'jellyfin.getUser';

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
            fn: fn() => $this->getUser($context, $opts),
            action: $this->action,
        );
    }

    /**
     * Fetch the users list from Jellyfin API.
     *
     * @throws ExceptionInterface When the request fails.
     * @throws JsonException When the response is not a valid JSON.
     */
    private function getUser(Context $context, array $opts = []): Response
    {
        $logContext = [
            'action' => $this->action,
            'identity' => [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
            ],
        ];

        if (null === $context->backendUser) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{identity.user}@{identity.backend}' user info failed. User not set.",
                    context: $logContext,
                    level: Levels::ERROR,
                ),
            );
        }

        $url = $context->backendUrl->withPath('/Users/' . $context->backendUser);

        $logContext['request']['url'] = (string) $url;
        $logContext['userId'] = $context->backendUser;

        $this->logger->debug(
            "Requesting '{identity.user}@{identity.backend}' user '{userId}' info.",
            $logContext,
        );

        $options = $context->getHttpOptions();

        if (count($options['headers']) < 1) {
            $options['headers']['Authorization'] = r(
                'MediaBrowser Token="{token}", Client="{app}", Device="{os}", DeviceId="{id}", Version="{version}", UserId="{user}"',
                [
                    'token' => $context->backendToken,
                    'app' => Config::get('name') . '/' . $context->clientName,
                    'os' => PHP_OS,
                    'id' => md5(Config::get('name') . '/' . $context->clientName),
                    'version' => get_app_version(),
                    'user' => $context->backendUser,
                ],
            );
        }

        $response = $this->http->request(Method::GET, (string) $url, $options);

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            $body = $response->getContent(false);
            $reason = $this->getBackendResponseReason($body);

            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{identity.user}@{identity.backend}' user '{userId}' info returned HTTP {response.status_code}.",
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
            $this->logger->debug("Parsing '{identity.user}@{identity.backend}' user '{userId}' info payload.", [
                ...$logContext,
                'response' => ['body' => $json],
            ]);
        }

        $date = ag($json, ['LastActivityDate', 'LastLoginDate'], null);

        $data = [
            'id' => ag($json, 'Id'),
            'name' => ag($json, 'Name'),
            'admin' => (bool) ag($json, 'Policy.IsAdministrator'),
            'hidden' => (bool) ag($json, 'Policy.IsHidden'),
            'disabled' => (bool) ag($json, 'Policy.IsDisabled'),
            'updatedAt' => null !== $date ? make_date($date) : 'Never',
        ];

        if (true === (bool) ag($opts, Options::RAW_RESPONSE)) {
            $data[Options::RAW_RESPONSE] = $json;
        }

        return new Response(status: true, response: $data);
    }
}
