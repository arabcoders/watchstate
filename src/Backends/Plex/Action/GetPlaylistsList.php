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
use App\Libs\Options;
use JsonException;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

final class GetPlaylistsList
{
    use CommonTrait;

    private string $action = 'plex.getPlaylistsList';

    /**
     * @param iHttp&HttpClient $http HTTP client.
     * @param iLogger $logger Logger.
     */
    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
    ) {}

    public function __invoke(Context $context, array $opts = []): Response
    {
        return $this->tryResponse(context: $context, fn: fn() => $this->action($context, $opts), action: $this->action);
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function action(Context $context, array $opts = []): Response
    {
        $url = $context
            ->backendUrl
            ->withPath('/playlists')
            ->withQuery(http_build_query(['playlistType' => 'video']));

        $logContext = [
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'url' => (string) $url,
        ];

        $this->logger->debug(
            message: "Requesting playlists list from '{user}@{backend}'.",
            context: [
                ...$logContext,
                'event_name' => 'backend.request.started',
                'subsystem' => 'backend.playlist',
                'operation' => 'list',
                'outcome' => 'started',
                'http' => ['url' => (string) $url],
            ],
        );

        $response = $this->http->request(Method::GET, (string) $url, $context->getHttpOptions());

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Playlists list request to '{user}@{backend}' returned status {http.status_code}.",
                    context: [
                        ...$logContext,
                        'event_name' => 'backend.response.failed',
                        'subsystem' => 'backend.playlist',
                        'operation' => 'list',
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

        $list = [];

        foreach (ag($json, 'MediaContainer.Metadata', []) as $item) {
            $playlistType = strtolower((string) ag($item, 'playlistType', 'video'));
            if ('video' !== $playlistType) {
                continue;
            }

            $specialType = trim((string) ag($item, 'specialPlaylistType', ''));

            $builder = [
                'id' => (string) ag($item, 'ratingKey'),
                'title' => (string) ag($item, 'title', 'Untitled playlist'),
                'type' => $playlistType,
                'summary' => ag($item, 'summary'),
                'editable' => false === (bool) ag($item, 'readOnly', false) && '' === $specialType,
                'smart' => true === (bool) ag($item, 'smart', false),
                'public' => false,
                'itemCount' => (int) ag($item, 'leafCount', 0),
                'remote_updated_at' => (int) ag($item, 'updatedAt', 0),
            ];

            if (true === (bool) ag($opts, Options::RAW_RESPONSE, false)) {
                $builder['raw'] = $item;
            }

            $list[] = $builder;
        }

        return new Response(status: true, response: $list);
    }
}
