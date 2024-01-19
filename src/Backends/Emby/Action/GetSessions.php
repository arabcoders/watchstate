<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class GetSessions extends \App\Backends\Jellyfin\Action\GetSessions
{
    protected string $action = 'emby.getSessions';
}
