<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class HealthCheck
{
    public const string URL = '%{api.prefix}/system/healthcheck';

    #[Get(self::URL . '[/]', name: 'system.healthcheck')]
    public function __invoke(iRequest $request): iResponse
    {
        return api_response(
            Status::OK,
            [
                'status' => 'ok',
                'message' => 'System is healthy',
            ],
            headers: ['X-No-AccessLog' => '1'],
        );
    }
}
