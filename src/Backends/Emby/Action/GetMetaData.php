<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class GetMetaData extends \App\Backends\Jellyfin\Action\GetMetaData
{
    protected string $action = 'emby.getMetadata';
}
