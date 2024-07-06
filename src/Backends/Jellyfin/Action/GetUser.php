<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
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
     * @param iHttp $http The HTTP client instance.
     * @param iLogger $logger The logger instance.
     */
    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
    }

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
            action: $this->action
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
        if (null === $context->backendUser) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{client}: {backend}' user info failed. User not set.",
                    context: [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        $url = $context->backendUrl->withPath('/Users/' . $context->backendUser);

        $this->logger->debug("Requesting '{client}: {backend}' user '{user}' info.", [
            'user' => $context->backendUrl,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        $headers = $context->backendHeaders;

        if (empty($headers)) {
            $headers = [
                'headers' => [
                    'X-MediaBrowser-Token' => $context->backendToken,
                ],
            ];
        }

        $response = $this->http->request('GET', (string)$url, $headers);

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{client}: {backend}' user '{user}' info returned with unexpected '{status_code}' status code.",
                    context: [
                        'client' => $context->clientName,
                        'backend' => $context->backendName,
                        'user' => $context->backendUser,
                        'status_code' => $response->getStatusCode(),
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
            $this->logger->debug("Parsing '{client}: {backend}' user '{user}' info payload.", [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->backendUser,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        $date = ag($json, ['LastActivityDate', 'LastLoginDate'], null);

        $data = [
            'id' => ag($json, 'Id'),
            'name' => ag($json, 'Name'),
            'admin' => (bool)ag($json, 'Policy.IsAdministrator'),
            'hidden' => (bool)ag($json, 'Policy.IsHidden'),
            'disabled' => (bool)ag($json, 'Policy.IsDisabled'),
            'updatedAt' => null !== $date ? makeDate($date) : 'Never',
        ];

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $data[Options::RAW_RESPONSE] = $json;
        }

        return new Response(status: true, response: $data);
    }
}
