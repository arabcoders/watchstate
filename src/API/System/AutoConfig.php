<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class AutoConfig
{
    public const string URL = '%{api.prefix}/system/auto';

    #[Post(self::URL . '[/]', name: 'system.autoconfig')]
    public function __invoke(iRequest $request): iResponse
    {
        if (false === (bool)Config::get('api.auto', false)) {
            return api_error('auto configuration is disabled.', Status::FORBIDDEN);
        }

        $data = DataUtil::fromRequest($request);

        return api_response(Status::OK, [
            'url' => $data->get('origin', ag($_SERVER, 'HTTP_ORIGIN', 'localhost')),
            'path' => Config::get('api.prefix'),
            'token' => Config::get('api.key'),
        ]);
    }
}
