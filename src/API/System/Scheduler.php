<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;

final class Scheduler
{
    public const string URL = '%{api.prefix}/system/scheduler';

    #[Get(self::URL . '[/]', name: 'system.task_scheduler.status')]
    public function status(): iResponse
    {
        return api_response(Status::OK, get_worker_status(), headers: [
            'X-No-AccessLog' => '1',
        ]);
    }

    #[Post(self::URL . '/restart[/]', name: 'system.task_scheduler.restart')]
    public function restart(): iResponse
    {
        if (!in_container()) {
            return api_error('WatchState is not running in a container.', Status::BAD_REQUEST);
        }

        return api_response(Status::OK, restart_worker());
    }
}
