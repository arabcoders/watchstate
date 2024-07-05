<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

final class GetUser extends \App\Backends\Jellyfin\Action\GetUsersList
{
    protected string $action = 'emby.getUser';
}
