<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

class UpdateState extends \App\Backends\Jellyfin\Action\UpdateState
{
    protected string $action = 'emby.UpdateState';
}
