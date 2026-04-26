<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Jellyfin\Action\DeletePlaylist as JellyfinDeletePlaylist;
use App\Libs\Enums\Http\Status;

final class DeletePlaylist extends JellyfinDeletePlaylist
{
    protected string $action = 'emby.deletePlaylist';

    /**
     * @return array<int,Status>
     */
    protected function getExpectedStatuses(): array
    {
        return [Status::OK, Status::NO_CONTENT];
    }
}
