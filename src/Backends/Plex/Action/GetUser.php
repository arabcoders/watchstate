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
use JsonException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GetUser
{
    use CommonTrait;

    private int $maxRetry = 3;
    private string $action = 'plex.getUser';
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
            fn: fn() => $this->getUser($context, $opts),
            action: $this->action
        );
    }

    /**
     * Get User list.
     *
     * @throws ExceptionInterface
     * @throws JsonException if JSON decoding fails.
     * @throws InvalidArgumentException if user id is not found.
     */
    private function getUser(Context $context, array $opts = []): Response
    {
        $url = Container::getNew(UriInterface::class)->withPort(443)->withScheme('https')
            ->withHost('clients.plex.tv')->withPath('/api/v2/home/users');

        $tokenType = 'user';

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

        if (Status::HTTP_OK->value !== $response->getStatusCode()) {
            $message = "Request for '{backend}' user info returned with unexpected '{status_code}' status code. Using {type} token.";

            if (null !== ag($context->options, Options::ADMIN_TOKEN)) {
                $adminResponse = $this->http->request('GET', (string)$url, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'X-Plex-Token' => ag($context->options, Options::ADMIN_TOKEN),
                        'X-Plex-Client-Identifier' => $context->backendId,
                    ],
                ]);
                if (Status::HTTP_OK->value === $adminResponse->getStatusCode()) {
                    return $this->process($context, $url, $adminResponse, $opts);
                }

                $tokenType = 'user and admin';
            }

            return new Response(
                status: false,
                error: new Error(
                    message: $message,
                    context: [
                        'backend' => $context->backendName,
                        'status_code' => $response->getStatusCode(),
                        'body' => $response->getContent(),
                        'type' => $tokenType,
                    ],
                    level: Levels::ERROR
                ),
            );
        }

        return $this->process($context, $url, $response, $opts);
    }

    /**
     * Process the actual response.
     *
     * @param Context $context
     * @param UriInterface $url
     * @param ResponseInterface $response
     * @param array $opts
     *
     * @return Response Return processed response.
     * @throws ExceptionInterface
     * @throws JsonException
     * @throws InvalidArgumentException
     */
    private function process(Context $context, UriInterface $url, ResponseInterface $response, array $opts): Response
    {
        $payload = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug("Parsing '{backend}' user info payload.", [
                'backend' => $context->backendName,
                'url' => (string)$url,
                'trace' => $payload,
            ]);
        }

        $data = [];

        foreach (ag($payload, 'users', []) as $json) {
            if ((int)$context->backendUser !== (int)ag($json, 'id')) {
                continue;
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

            break;
        }

        if (empty($data)) {
            throw new InvalidArgumentException(r("Did not find matching user id '{id}' in users list.", [
                'id' => $context->backendUser,
            ]));
        }

        return new Response(status: true, response: $data);
    }
}
