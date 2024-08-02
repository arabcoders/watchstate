<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Report
{
    public const string URL = '%{api.prefix}/system/report';

    #[Get(self::URL . '[/]', name: 'system.report')]
    public function __invoke(iRequest $request): iResponse
    {
        return api_response(Status::HTTP_OK, runCommand('system:report', asArray: true));
    }
}
