<?php

declare(strict_types=1);

namespace App\Backends\Plex\Action;

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

final class DeletePlaylist
{
    use CommonTrait;

    private string $action = 'plex.deletePlaylist';

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
    private function action(Context $context, string|int $id): Response
    {
        $url = $context->backendUrl->withPath(r('/playlists/{playlist_id}', ['playlist_id' => $id]));
        $logContext = [
            'action' => $this->action,
            'identity' => [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
            ],
            'id' => $id,
            'request' => ['url' => (string) $url],
        ];

        $response = $this->http->request(Method::DELETE, (string) $url, $context->getHttpOptions());

        if (Status::NO_CONTENT !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{identity.user}@{identity.backend}' playlist '{id}' returned HTTP {response.status_code}.",
                    context: [...$logContext, 'response' => ['status_code' => $response->getStatusCode()]],
                    level: Levels::ERROR,
                ),
            );
        }

        return new Response(status: true, response: ['id' => (string) $id]);
    }
}
