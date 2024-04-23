<?php

declare(strict_types=1);

namespace App\API\System;

use App\Libs\Attributes\Route\Get;
use App\Libs\HTTP_STATUS;
use App\Libs\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

#[Get(self::URL . '[/]', name: 'system.env')]
final class Env
{
    public const string URL = '%{api.prefix}/system/env';

    private const array BLACKLIST = [
        'WS_API_KEY'
    ];

    private const array BLACKLIST_PARSE_URL = [
        'WS_CACHE_URL' => [
            'password',
        ],
    ];

    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $response = [
            'data' => [],
            'links' => [
                'self' => (string)$request->getUri()->withHost('')->withPort(0)->withScheme(''),
            ],
        ];

        foreach ($_ENV as $key => $val) {
            if (false === str_starts_with($key, 'WS_') && $key !== 'HTTP_PORT') {
                continue;
            }

            try {
                if (array_key_exists($key, self::BLACKLIST_PARSE_URL)) {
                    $val = new Uri($val);
                    $query = $val->getQuery();
                    $auth = $val->getUserInfo();
                    if (!empty($auth) && str_contains($auth, ':')) {
                        $val = $val->withUserInfo(before($auth, ':'), '__hidden__');
                    }
                    if (!empty($query)) {
                        parse_str($query, $q);
                        foreach ($q ?? [] as $k => $v) {
                            if (false === in_array(strtolower($k), self::BLACKLIST_PARSE_URL[$key], true)) {
                                continue;
                            }
                            $q[$k] = '__hidden__';
                        }
                        $val = $val->withQuery(http_build_query($q));
                    }
                    $val = (string)$val;
                }
            } catch (Throwable) {
            }

            if (in_array($key, self::BLACKLIST, true)) {
                $val = '__hidden__';
            }

            $response['data'][] = [
                'key' => $key,
                'value' => $val,
            ];
        }

        return api_response(HTTP_STATUS::HTTP_OK, $response);
    }
}
