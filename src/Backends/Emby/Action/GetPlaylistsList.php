<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Common\Context;
use App\Backends\Emby\EmbyClient;
use Psr\Http\Message\UriInterface as iUri;

final class GetPlaylistsList extends \App\Backends\Jellyfin\Action\GetPlaylistsList
{
    protected string $action = 'emby.getPlaylistsList';

    protected function makeUrl(Context $context): iUri
    {
        // Emby returns unrelated video metadata rows when MediaTypes=Video is combined with IncludeItemTypes=Playlist.
        return $context
            ->backendUrl
            ->withPath(r('/Users/{user_id}/items/', ['user_id' => $context->backendUser]))
            ->withQuery(http_build_query([
                'recursive' => 'true',
                'fields' => implode(',', EmbyClient::EXTRA_FIELDS),
                'enableUserData' => 'false',
                'enableImages' => 'false',
                'includeItemTypes' => 'Playlist',
            ]));
    }
}
