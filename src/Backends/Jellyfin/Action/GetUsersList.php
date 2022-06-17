<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Common\Context;
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GetUsersList
{
    use CommonTrait;

    public function __construct(protected HttpClientInterface $http, protected LoggerInterface $logger)
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
        return $this->tryResponse(context: $context, fn: fn() => $this->getUsers($context, $opts));
    }

    /**
     * Get Users list.
     *
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function getUsers(Context $context, array $opts = []): Response
    {
        $url = $context->backendUrl->withPath('/Users/');

        $this->logger->debug('Requesting [%(backend)] Users list.', [
            'backend' => $context->backendName,
            'url' => (string)$url,
        ]);

        $response = $this->http->request('GET', (string)$url, $context->backendHeaders);

        if (200 !== $response->getStatusCode()) {
            return new Response(
                status: false,
                error:  new Error(
                            message: 'Request for [%(backend)] users list returned with unexpected [%(status_code)] status code.',
                            context: [
                                         'backend' => $context->backendName,
                                         'status_code' => $response->getStatusCode(),
                                     ],
                            level:   Levels::ERROR
                        ),
            );
        }

        $json = json_decode(
            json:        $response->getContent(),
            associative: true,
            flags:       JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
        );

        if ($context->trace) {
            $this->logger->debug('Parsing [%(backend)] user list payload.', [
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
