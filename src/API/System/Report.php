<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;

final class Report
{
    public const string URL = '%{api.prefix}/system/report';

    #[Get(self::URL . '[/]', name: 'system.report')]
    public function basic_report(): iResponse
    {
        return api_response(Status::OK, runCommand('system:report', asArray: true));
    }

    #[Get(self::URL . '/ini[/]', name: 'system.ini')]
    public function php_ini(): iResponse
    {
        if (false === str_starts_with(getAppVersion(), 'dev')) {
            return api_error('This endpoint is only available in development mode.', Status::FORBIDDEN);
        }

        return api_response(Status::OK, ['content' => ini_get_all()]);
    }
}
