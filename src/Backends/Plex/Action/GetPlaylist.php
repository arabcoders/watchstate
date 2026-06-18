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

final class GetPlaylist
{
    use CommonTrait;

    private string $action = 'plex.getPlaylist';

    /**
     * @param iHttp&HttpClient $http HTTP client.
     * @param iLogger $logger Logger.
     */
    public function __construct(
        protected readonly iHttp $http,
        protected readonly iLogger $logger,
    ) {}

    public function __invoke(Context $context, string|int $id, array $opts = []): Response
    {
        return $this->tryResponse(
            context: $context,
            fn: fn() => $this->action($context, $id, $opts),
            action: $this->action,
        );
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    private function action(Context $context, string|int $id, array $opts = []): Response
    {
        $detailUrl = $context->backendUrl->withPath(r('/playlists/{playlist_id}', ['playlist_id' => $id]));
        $itemsUrl = $context->backendUrl->withPath(r('/playlists/{playlist_id}/items', ['playlist_id' => $id]));

        $logContext = [
            'action' => $this->action,
            'identity' => [
                'client' => $context->clientName,
                'backend' => $context->backendName,
                'user' => $context->userContext->name,
            ],
            'id' => $id,
        ];

        $detailResponse = $this->http->request(Method::GET, (string) $detailUrl, $context->getHttpOptions());
        if (Status::OK !== Status::tryFrom($detailResponse->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{identity.user}@{identity.backend}' playlist '{id}' returned with unexpected '{response.status_code}' status code.",
                    context: [...$logContext, 'response' => ['status_code' => $detailResponse->getStatusCode()]],
                    level: Levels::ERROR,
                ),
            );
        }

        $detailJson = json_decode(
            json: $detailResponse->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        $detail = ag($detailJson, 'MediaContainer.Metadata.0', []);

        if ([] === $detail) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Playlist '{id}' was not found in '{identity.user}@{identity.backend}'.",
                    context: $logContext,
                    level: Levels::WARNING,
                ),
            );
        }

        $itemsResponse = $this->http->request(Method::GET, (string) $itemsUrl, $context->getHttpOptions());
        if (Status::OK !== Status::tryFrom($itemsResponse->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Request for '{identity.user}@{identity.backend}' playlist '{id}' items returned with unexpected '{response.status_code}' status code.",
                    context: [...$logContext, 'response' => ['status_code' => $itemsResponse->getStatusCode()]],
                    level: Levels::ERROR,
                ),
            );
        }

        $itemsJson = json_decode(
            json: $itemsResponse->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        $specialType = trim((string) ag($detail, 'specialPlaylistType', ''));
        $playlist = [
            'id' => (string) ag($detail, 'ratingKey', $id),
            'title' => (string) ag($detail, 'title', 'Untitled playlist'),
            'type' => strtolower((string) ag($detail, 'playlistType', 'video')),
            'summary' => ag($detail, 'summary'),
            'editable' => false === (bool) ag($detail, 'readOnly', false) && '' === $specialType,
            'smart' => true === (bool) ag($detail, 'smart', false),
            'public' => false,
            'itemCount' => (int) ag($detail, 'leafCount', count(ag($itemsJson, 'MediaContainer.Metadata', []))),
            'items' => array_values(ag($itemsJson, 'MediaContainer.Metadata', [])),
        ];

        if (true === (bool) ag($opts, Options::RAW_RESPONSE, false)) {
            $playlist['raw'] = [
                'detail' => $detail,
                'items' => ag($itemsJson, 'MediaContainer.Metadata', []),
            ];
        }

        return new Response(status: true, response: $playlist);
    }
}
