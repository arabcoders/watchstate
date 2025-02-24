<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class Proxy extends \App\Backends\Jellyfin\Action\Proxy
{
    protected string $action = 'emby.proxy';
}
