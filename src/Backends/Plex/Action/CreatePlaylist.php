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
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class CreatePlaylist
{
    use CommonTrait;

    private string $action = 'plex.createPlaylist';

    /**
     * @param iHttp&HttpClient $http HTTP client.
     * @param iLogger $logger Logger.
     */
    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
    ) {}

    /**
     * Create a playlist.
     *
     * @param Context $context Backend context.
     * @param string $title Playlist title.
     * @param array<int,string> $itemIds Playlist item ids.
     * @param array<string,mixed> $opts Optional options.
     *
     * @return Response
     */
    public function __invoke(Context $context, string $title, array $itemIds = [], array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $title, $itemIds),
            action: $this->action,
        );
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function action(Context $context, string $title, array $itemIds): Response
    {
        $machineIdentifier = trim((string) ($context->backendId ?? ''));
        $logContext = [
            'action' => $this->action,
            'identity' => [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
            ],
            'title' => $title,
        ];

        if ('' === $machineIdentifier) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Missing machine identifier for '{identity.client}: {identity.user}@{identity.backend}' playlist create request.",
                    context: $logContext,
                    level: Levels::ERROR,
                ),
            );
        }

        $uri = 0 === count($itemIds)
            ? ''
            : sprintf(
                'server://%s/com.plexapp.plugins.library/library/metadata/%s',
                $machineIdentifier,
                implode(',', $itemIds),
            );

        $url = $context
            ->backendUrl
            ->withPath('/playlists')
            ->withQuery(http_build_query([
                'title' => $title,
                'smart' => 0,
                'type' => 'video',
                'uri' => $uri,
            ]));

        $response = $this->http->request(Method::POST, (string) $url, $context->getHttpOptions());

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Request for '{identity.client}: {identity.user}@{identity.backend}' playlist '{title}' returned with unexpected '{status_code}' status code.",
                    context: [...$logContext, 'status_code' => $response->getStatusCode(), 'url' => (string) $url],
                    level: Levels::ERROR,
                ),
            );
        }

        $json = json_decode(
            json: $response->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        $playlist = ag($json, 'MediaContainer.Metadata.0', []);

        return new Response(status: true, response: [
            'id' => (string) ag($playlist, 'ratingKey', ''),
            'raw' => $playlist,
        ]);
    }
}
