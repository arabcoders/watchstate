<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class GetIdentifier extends \App\Backends\Jellyfin\Action\GetIdentifier
{
    protected string $action = 'emby.getIdentifier';
}
