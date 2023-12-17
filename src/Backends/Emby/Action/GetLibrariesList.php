<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class GetLibrariesList extends \App\Backends\Jellyfin\Action\GetLibrariesList
{
    protected string $action = 'emby.getLibrariesList';
}
