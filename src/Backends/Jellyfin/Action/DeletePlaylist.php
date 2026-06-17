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
use App\Libs\Extends\HttpClient;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class DeletePlaylist
{
    use CommonTrait;

    protected string $action = 'jellyfin.deletePlaylist';

    /**
     * @param iHttp&HttpClient $http HTTP client.
     * @param iLogger $logger Logger.
     */
    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
    ) {}

    /**
     * Delete a playlist.
     *
     * @param Context $context Backend context.
     * @param string|int $id Playlist id.
     * @param array<string,mixed> $opts Optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $id),
            action: $this->action,
        );
    }

    /**
     * @throws ExceptionInterface
     */
    protected function action(Context $context, string|int $id): Response
    {
        $url = $context->backendUrl->withPath(r('/Items/{item_id}', ['item_id' => $id]));
        $logContext = [
            'action' => $this->action,
            'identity' => [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
            ],
            'id' => $id,
            'url' => (string) $url,
        ];

        $response = $this->http->request(Method::DELETE, (string) $url, $context->getHttpOptions());

        if (false === in_array(Status::tryFrom($response->getStatusCode()), $this->getExpectedStatuses(), true)) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Request for '{identity.client}: {identity.user}@{identity.backend}' playlist '{id}' returned with unexpected '{status_code}' status code.",
                    context: [...$logContext, 'status_code' => $response->getStatusCode()],
                    level: Levels::ERROR,
                ),
            );
        }

        return new Response(status: true, response: ['id' => (string) $id]);
    }

    /**
     * @return array<int,Status>
     */
    protected function getExpectedStatuses(): array
    {
        return [Status::NO_CONTENT];
    }
}
