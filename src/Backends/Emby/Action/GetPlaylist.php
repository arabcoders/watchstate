<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

use App\Backends\Emby\EmbyClient;
use App\Backends\Jellyfin\Action\GetPlaylist as JellyfinGetPlaylist;

final class GetPlaylist extends JellyfinGetPlaylist
{
    protected string $action = 'emby.getPlaylist';

    /**
     * @return array<int,string>
     */
    protected function getExtraFields(): array
    {
        return EmbyClient::EXTRA_FIELDS;
    }

    /**
     * @param array<string,mixed> $detail
     */
    protected function isEditable(array $detail): bool
    {
        return (
            true === (bool) ag($detail, 'CanDelete', false)
            || 'playlist' === strtolower((string) ag($detail, 'Type', ''))
            && true === (bool) ag($detail, 'SupportsSync', false)
        );
    }

    /**
     * @param array<string,mixed> $detail
     */
    protected function isPublic(array $detail): bool
    {
        return false;
    }
}
