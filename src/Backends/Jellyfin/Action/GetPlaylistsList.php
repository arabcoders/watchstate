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

class GetPlaylistsList
{
    use CommonTrait;

    protected string $action = 'jellyfin.getPlaylistsList';

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
    protected function action(Context $context, array $opts = []): Response
    {
        $url = $this->makeUrl($context);

        $logContext = [
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'url' => (string) $url,
        ];

        $this->logger->debug("{action}: Requesting '{client}: {user}@{backend}' playlists list.", $logContext);

        $response = $this->http->request(Method::GET, (string) $url, $context->getHttpOptions());

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Request for '{client}: {user}@{backend}' playlists returned with unexpected '{status_code}' status code.",
                    context: [...$logContext, 'status_code' => $response->getStatusCode()],
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

        foreach (ag($json, 'Items', []) as $item) {
            if (false === $this->isPlaylistItem($item)) {
                continue;
            }

            $remoteUpdatedAt = 0;
            if (is_string(ag($item, 'DateLastSaved'))) {
                $remoteUpdatedAt = make_date((string) ag($item, 'DateLastSaved'))->getTimestamp();
            } elseif (is_string(ag($item, 'DateCreated'))) {
                $remoteUpdatedAt = make_date((string) ag($item, 'DateCreated'))->getTimestamp();
            }

            $builder = [
                'id' => (string) ag($item, 'Id'),
                'title' => (string) ag($item, 'Name', 'Untitled playlist'),
                'type' => strtolower((string) ag($item, 'MediaType', 'video')),
                'summary' => ag($item, 'Overview'),
                'editable' => true === (bool) ag($item, 'CanDelete', false),
                'smart' => false,
                'public' => false,
                'itemCount' => (int) ag($item, ['ChildCount', 'RecursiveItemCount'], 0),
                'remote_updated_at' => $remoteUpdatedAt,
            ];

            if (true === (bool) ag($opts, Options::RAW_RESPONSE, false)) {
                $builder['raw'] = $item;
            }

            $list[] = $builder;
        }

        return new Response(status: true, response: $list);
    }

    protected function makeUrl(Context $context): iUri
    {
        return $context
            ->backendUrl
            ->withPath(r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]))
            ->withQuery(http_build_query([
                'recursive' => 'true',
                'fields' => implode(',', JellyfinClient::EXTRA_FIELDS),
                'enableUserData' => 'false',
                'enableImages' => 'false',
                'includeItemTypes' => 'Playlist',
                'mediaTypes' => 'Video',
            ]));
    }

    protected function isPlaylistItem(array $item): bool
    {
        return 'playlist' === strtolower((string) ag($item, 'Type', ''));
    }
}
