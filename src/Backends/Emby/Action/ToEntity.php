<?php

declare(strict_types=1);

namespace App\Backends\Emby\Action;

final class ToEntity extends \App\Backends\Jellyfin\Action\ToEntity
{
    protected string $action = 'emby.toEntity';
}
