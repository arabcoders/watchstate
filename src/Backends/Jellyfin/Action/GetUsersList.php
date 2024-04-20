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
            fn: fn() => $this->getUsers($context, $opts),
            action: $this->action
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
        $url = $context->backendUrl->withPath('/Users/');

        $this->logger->debug('Requesting [{backend}] Users list.', [
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
                    message: 'Request for [{backend}] users list returned with unexpected [{status_code}] status code.',
                    context: [
                        'backend' => $context->backendName,
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
            $this->logger->debug('Parsing [{backend}] user list payload.', [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        $list = [];

        foreach ($json ?? [] as $user) {
            $date = ag($user, ['LastActivityDate', 'LastLoginDate'], null);

            $data = [
                'id' => ag($user, 'Id'),
                'name' => ag($user, 'Name'),
                'admin' => (bool)ag($user, 'Policy.IsAdministrator'),
                'Hidden' => (bool)ag($user, 'Policy.IsHidden'),
                'disabled' => (bool)ag($user, 'Policy.IsDisabled'),
                'updatedAt' => null !== $date ? makeDate($date) : 'Never',
            ];

            if (true === (bool)ag($opts, 'tokens')) {
                $data['token'] = $context->backendToken;
            }

            if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
                $data['raw'] = $user;
            }

            $list[] = $data;
        }

        return new Response(status: true, response: $list);
    }
}
