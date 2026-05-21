<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
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
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
        ];

        if (null === $context->backendUser) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Skipping user info request for '{user}@{backend}': backend user is not set.",
                    context: [
                        ...$logContext,
                        'event_name' => 'backend.request.skipped',
                        'subsystem' => 'backend.user',
                        'operation' => 'load',
                        'outcome' => 'skipped',
                        'reason' => 'missing_backend_user',
                    ],
                    level: Levels::ERROR,
                ),
            );
        }

        $url = $context->backendUrl->withPath('/Users/' . $context->backendUser);

        $logContext['url'] = (string) $url;
        $logContext['userId'] = $context->backendUser;

        $this->logger->debug("Requesting user '{userId}' from '{user}@{backend}'.", [
            ...$logContext,
            'event_name' => 'backend.request.started',
            'subsystem' => 'backend.user',
            'operation' => 'load',
            'outcome' => 'started',
            'http' => ['url' => (string) $url],
        ]);

        $options = $context->getHttpOptions();

        if (count($options['headers']) < 1) {
            $options['headers']['X-MediaBrowser-Token'] = $context->backendToken;
        }

        $response = $this->http->request(Method::GET, (string) $url, $options);

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "User '{userId}' info request to '{user}@{backend}' returned status {http.status_code}.",
                    context: [
                        ...$logContext,
                        'event_name' => 'backend.response.failed',
                        'subsystem' => 'backend.user',
                        'operation' => 'load',
                        'outcome' => 'failed',
                        'reason' => 'unexpected_status',
                        'http' => [
                            'status_code' => $response->getStatusCode(),
                            'expected_status_codes' => [Status::OK->value],
                            'url' => (string) $url,
                        ],
                    ],
                    level: Levels::ERROR,
                ),
            );
        }
        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        if ($context->trace) {
            $this->logger->debug("Processing user '{userId}' response from '{user}@{backend}'.", [
                ...$logContext,
                'event_name' => 'backend.response.received',
                'subsystem' => 'backend.user',
                'operation' => 'load',
                'outcome' => 'received',
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
