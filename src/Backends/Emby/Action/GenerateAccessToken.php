<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

final class GenerateAccessToken extends \App\Backends\Jellyfin\Action\GenerateAccessToken
{
    protected string $action = 'emby.generateAccessToken';
}
