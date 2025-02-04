<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\Config;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class Supported
{
    public const string URL = '%{api.prefix}/system/supported';

    #[Get(self::URL . '[/]', name: 'system.supported')]
    public function __invoke(iRequest $request): iResponse
    {
        return api_response(Status::OK, array_keys(Config::get('supported')));
    }
}
