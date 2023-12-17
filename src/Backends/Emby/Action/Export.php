<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class Export extends \App\Backends\Jellyfin\Action\Export
{
    protected string $action = 'emby.export';
}
