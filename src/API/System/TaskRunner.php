<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;

final class TaskRunner
{
    public const string URL = '%{api.prefix}/system/taskrunner';

    private array $status;

    public function __construct()
    {
        $this->status = isTaskWorkerRunning();
    }

    #[Get(self::URL . '[/]', name: 'system.taskrunner.status')]
    public function status(): iResponse
    {
        return api_response(Status::OK, $this->status);
    }

    #[Post(self::URL . '/restart[/]', name: 'system.taskrunner.restart')]
    public function restart(): iResponse
    {
        if (true === (bool)env('DISABLE_CRON', false)) {
            return api_error("Task runner is disabled via 'DISABLE_CRON' environment variable.", Status::BAD_REQUEST);
        }

        if (!inContainer()) {
            return api_error('WatchState is not running in a container.', Status::BAD_REQUEST);
        }

        return api_response(Status::OK, restartTaskWorker());
    }
}
