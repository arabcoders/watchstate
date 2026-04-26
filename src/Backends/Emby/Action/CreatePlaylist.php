<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\Context;
use App\Backends\Jellyfin\Action\CreatePlaylist as JellyfinCreatePlaylist;
use Psr\Http\Message\UriInterface as iUri;

final class CreatePlaylist extends JellyfinCreatePlaylist
{
    protected string $action = 'emby.createPlaylist';

    /**
     * @param array<int,string> $itemIds
     */
    protected function makeUrl(Context $context, string $title, array $itemIds): iUri
    {
        return $context
            ->backendUrl
            ->withPath('/Playlists')
            ->withQuery(http_build_query([
                'Name' => $title,
                'Ids' => implode(',', $itemIds),
                'userId' => $context->backendUser,
                'MediaType' => 'Video',
            ]));
    }

    /**
     * @param array<int,string> $itemIds
     *
     * @return array<string,mixed>
     */
    protected function getRequestOptions(Context $context, string $title, array $itemIds): array
    {
        return $context->getHttpOptions();
    }
}
