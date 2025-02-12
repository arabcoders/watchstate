<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Response;
use App\Libs\Container;
use App\Libs\Exceptions\Backends\InvalidArgumentException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

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
     * @throws InvalidArgumentException if user id is not found.
     */
    private function getUser(Context $context, array $opts = []): Response
    {
        $users = Container::get(GetUsersList::class)($context, $opts);

        if ($users->hasError()) {
            return $users;
        }

        foreach ($users->response as $user) {
            if ((int)$user['id'] === (int)$context->backendUser) {
                return new Response(status: true, response: $user);
            }
        }

        throw new InvalidArgumentException(r("Did not find matching user id '{id}' in users list.", [
            'id' => $context->backendUser,
        ]));
    }
}
