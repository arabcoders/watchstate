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
use JsonException;
use Psr\Http\Message\UriInterface as iUri;
use Psr\Log\LoggerInterface as iLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

class CreatePlaylist
{
    use CommonTrait;

    protected string $action = 'jellyfin.createPlaylist';

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
    protected function action(Context $context, string $title, array $itemIds): Response
    {
        $url = $this->makeUrl(context: $context, title: $title, itemIds: $itemIds);
        $logContext = [
            'action' => $this->action,
            'client' => $context->clientName,
            'backend' => $context->backendName,
            'user' => $context->userContext->name,
            'title' => $title,
            'url' => (string) $url,
        ];

        $response = $this->http->request(
            Method::POST,
            (string) $url,
            $this->getRequestOptions(context: $context, title: $title, itemIds: $itemIds),
        );

        if (Status::OK !== Status::tryFrom($response->getStatusCode())) {
            return new Response(
                status: false,
                error: new Error(
                    message: "{action}: Request for '{client}: {user}@{backend}' playlist '{title}' returned with unexpected '{status_code}' status code.",
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

        return new Response(status: true, response: [
            'id' => (string) ag($json, 'Id', ''),
            'raw' => $json,
        ]);
    }

    /**
     * @param array<int,string> $itemIds
     */
    protected function makeUrl(Context $context, string $title, array $itemIds): iUri
    {
        return $context->backendUrl->withPath('/Playlists');
    }

    /**
     * @param array<int,string> $itemIds
     *
     * @return array<string,mixed>
     */
    protected function getRequestOptions(Context $context, string $title, array $itemIds): array
    {
        return array_replace_recursive($context->getHttpOptions(), [
            'json' => [
                'Name' => $title,
                'Ids' => array_values($itemIds),
                'UserId' => $context->backendUser,
                'MediaType' => 'Video',
                'IsPublic' => false,
            ],
        ]);
    }
}
