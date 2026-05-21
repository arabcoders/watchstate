<?php

declare(strict_types=1);

namespace App\Backends\Jellyfin\Action;

use App\Backends\Common\CommonTrait;
use App\Backends\Common\Context;
use App\Backends\Common\Error;
use App\Backends\Common\Levels;
use App\Backends\Common\Response;
use App\Backends\Jellyfin\JellyfinClient;
use App\Libs\Enums\Http\Method;
use App\Libs\Enums\Http\Status;
use App\Libs\Extends\HttpClient;
use App\Libs\Options;
use JsonException;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class GetPlaylist
{
    use CommonTrait;

    protected string $action = 'jellyfin.getPlaylist';

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
    protected function action(Context $context, string|int $id, array $opts = []): Response
    {
        $detailUrl = $this->makeDetailUrl(context: $context, id: $id);
        $itemsUrl = $this->makeItemsUrl(context: $context, id: $id);

        $logContext = [
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'id' => $id,
        ];

        $this->logger->debug(
            message: "Requesting playlist '{id}' from '{user}@{backend}'.",
            context: [
                ...$logContext,
                'event_name' => 'backend.request.started',
                'subsystem' => 'backend.playlist',
                'operation' => 'load',
                'outcome' => 'started',
                'http' => ['method' => Method::GET->value, 'url' => (string) $detailUrl],
            ],
        );

        $detailResponse = $this->http->request(Method::GET, (string) $detailUrl, $context->getHttpOptions());
        if (Status::OK !== Status::tryFrom($detailResponse->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Playlist '{id}' request to '{user}@{backend}' returned status {http.status_code}.",
                    context: [
                        ...$logContext,
                        'event_name' => 'backend.response.failed',
                        'subsystem' => 'backend.playlist',
                        'operation' => 'load',
                        'outcome' => 'failed',
                        'reason' => 'unexpected_status',
                        'http' => [
                            'method' => Method::GET->value,
                            'status_code' => $detailResponse->getStatusCode(),
                            'expected_status_codes' => [Status::OK->value],
                            'url' => (string) $detailUrl,
                        ],
                    ],
                    level: Levels::ERROR,
                ),
            );
        }

        $detail = json_decode(
            json: $detailResponse->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        $itemsResponse = $this->http->request(Method::GET, (string) $itemsUrl, $context->getHttpOptions());
        if (Status::OK !== Status::tryFrom($itemsResponse->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "Playlist items request for '{id}' on '{user}@{backend}' returned status {http.status_code}.",
                    context: [
                        ...$logContext,
                        'event_name' => 'backend.response.failed',
                        'subsystem' => 'backend.playlist',
                        'operation' => 'load_items',
                        'outcome' => 'failed',
                        'reason' => 'unexpected_status',
                        'http' => [
                            'method' => Method::GET->value,
                            'status_code' => $itemsResponse->getStatusCode(),
                            'expected_status_codes' => [Status::OK->value],
                            'url' => (string) $itemsUrl,
                        ],
                    ],
                    level: Levels::ERROR,
                ),
            );
        }

        $itemsJson = json_decode(
            json: $itemsResponse->getContent(),
            associative: true,
            flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE,
        );

        $playlist = $this->makePlaylistPayload(id: $id, detail: $detail, itemsJson: $itemsJson);

        if (true === (bool) ag($opts, Options::RAW_RESPONSE, false)) {
            $playlist['raw'] = [
                'detail' => $detail,
                'items' => ag($itemsJson, 'Items', []),
            ];
        }

        return new Response(status: true, response: $playlist);
    }

    protected function makeDetailUrl(Context $context, string|int $id): iUri
    {
        return $context
            ->backendUrl
            ->withPath(r('/Users/{user_id}/items/{item_id}', ['user_id' => $context->backendUser, 'item_id' => $id]))
            ->withQuery(http_build_query([
                'fields' => implode(',', $this->getExtraFields()),
                'enableUserData' => 'true',
                'enableImages' => 'false',
            ]));
    }

    protected function makeItemsUrl(Context $context, string|int $id): iUri
    {
        return $context
            ->backendUrl
            ->withPath(r('/Playlists/{playlist_id}/Items', ['playlist_id' => $id]))
            ->withQuery(http_build_query([
                'userId' => $context->backendUser,
                'fields' => implode(',', $this->getExtraFields()),
                'enableUserData' => 'true',
                'enableImages' => 'false',
            ]));
    }

    /**
     * @return array<int,string>
     */
    protected function getExtraFields(): array
    {
        return JellyfinClient::EXTRA_FIELDS;
    }

    /**
     * @param array<string,mixed> $detail
     * @param array<string,mixed> $itemsJson
     *
     * @return array<string,mixed>
     */
    protected function makePlaylistPayload(string|int $id, array $detail, array $itemsJson): array
    {
        return [
            'id' => (string) ag($detail, 'Id', $id),
            'title' => (string) ag($detail, 'Name', 'Untitled playlist'),
            'type' => strtolower((string) ag($detail, 'MediaType', 'video')),
            'summary' => ag($detail, 'Overview'),
            'editable' => $this->isEditable($detail),
            'smart' => false,
            'public' => $this->isPublic($detail),
            'itemCount' => (int) ag($itemsJson, 'TotalRecordCount', count(ag($itemsJson, 'Items', []))),
            'items' => array_values(ag($itemsJson, 'Items', [])),
        ];
    }

    /**
     * @param array<string,mixed> $detail
     */
    protected function isEditable(array $detail): bool
    {
        $canEdit = true === (bool) ag($detail, 'CanEdit', false);

        foreach (ag($detail, 'Shares', []) as $share) {
            if (true !== (bool) ag($share, 'CanEdit', false)) {
                continue;
            }

            $canEdit = true;
            break;
        }

        return $canEdit || true === (bool) ag($detail, 'CanDelete', false);
    }

    /**
     * @param array<string,mixed> $detail
     */
    protected function isPublic(array $detail): bool
    {
        return true === (bool) ag($detail, 'OpenAccess', ag($detail, 'IsPublic', false));
    }
}
