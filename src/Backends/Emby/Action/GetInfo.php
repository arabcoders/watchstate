<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class GetInfo extends \App\Backends\Jellyfin\Action\GetInfo
{
    protected string $action = 'emby.getInfo';
}
