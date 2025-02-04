<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class Backup extends \App\Backends\Jellyfin\Action\Backup
{
    protected string $action = 'emby.backup';
}
