<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\HTTP_STATUS;
use App\Libs\Options;
use JsonException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GetUser
{
    private int $maxRetry = 3;
    private string $action = 'plex.getUser';

    use CommonTrait;

    public function __construct(protected iHttp $http, protected iLogger $logger)
    {
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
            fn: fn() => $this->getUser($context, $opts),
            action: $this->action
        );
    }

    /**
     * Get User list.
     *
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function getUser(Context $context, array $opts = []): Response
    {
        $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')
            ->withHost('clients.plex.tv')->withPath('/api/v2/user');

        $response = $this->http->request('GET', (string)$url, [
            'headers' => [
                'Accept' => 'application/json',
                'X-Plex-Token' => $context->backendToken,
                'X-Plex-Client-Identifier' => $context->backendId,
            ],
        ]);

        $this->logger->debug("Requesting '{backend}' user info.", [
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        if (HTTP_STATUS::HTTP_OK->value !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{backend}' user info returned with unexpected '{status_code}' status code.",
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
            $this->logger->debug("Parsing '{backend}' user info payload.", [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $json,
            ]);
        }

        $name = '??';
        $possibleName = ['friendlyName', 'username', 'title', 'email'];
        foreach ($possibleName as $key) {
            $val = ag($json, $key);
            if (empty($val)) {
                continue;
            }
            $name = $val;
            break;
        }

        $data = [
            'id' => ag($json, 'id'),
            'uuid' => ag($json, 'uuid'),
            'name' => $name,
            'home' => (bool)ag($json, 'home'),
            'guest' => (bool)ag($json, 'guest'),
            'restricted' => (bool)ag($json, 'restricted'),
            'joinedAt' => isset($json['joinedAt']) ? makeDate($json['joinedAt']) : 'Unknown',
        ];

        if (true === (bool)ag($opts, 'tokens')) {
            $data['token'] = ag($json, 'authToken');
        }

        if (true === (bool)ag($opts, Options::RAW_RESPONSE)) {
            $data['raw'] = $json;
        }

        return new Response(status: true, response: $data);
    }
}
