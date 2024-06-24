<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Post;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;

final class AutoConfig
{
    public const string URL = '%{api.prefix}/system/auto';

    #[Post(self::URL . '[/]', name: 'system.autoconfig')]
    public function __invoke(iRequest $request): iResponse
    {
        if (false === (bool)Config::get('api.auto', false)) {
            return api_error('auto configuration is disabled.', HTTP_STATUS::HTTP_FORBIDDEN);
        }

        $data = DataUtil::fromRequest($request);

        return api_response(HTTP_STATUS::HTTP_OK, [
            'url' => $data->get('origin', ag($_SERVER, 'HTTP_ORIGIN', 'localhost')),
            'path' => Config::get('api.prefix'),
            'token' => Config::get('api.key'),
        ]);
    }
}
