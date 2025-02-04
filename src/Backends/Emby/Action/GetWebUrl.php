<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class GetWebUrl extends \App\Backends\Jellyfin\Action\GetWebUrl
{
    protected string $action = 'emby.getWebUrl';
}
