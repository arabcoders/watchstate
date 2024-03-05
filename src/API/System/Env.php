<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Get(self::URL . '[/]', name: 'system.env')]
final class Env
{
    public const URL = '%{api.prefix}/system/env';

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $response = [
            '@self' => (string)$request->getUri()->withHost('')->withPort(0)->withScheme(''),
            'data' => [],
        ];

        foreach (getenv() as $key => $val) {
            if (false === str_starts_with($key, 'WS_') && $key !== 'HTTP_PORT') {
                continue;
            }
            $response['data'][] = [
                'key' => $key,
                'value' => $val,
            ];
        }

        return api_response($response, HTTP_STATUS::HTTP_OK, []);
    }
}
